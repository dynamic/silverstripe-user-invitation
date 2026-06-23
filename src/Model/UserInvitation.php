<?php

namespace Dynamic\SilverStripe\UserInvitations\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Control\Director;
use SilverStripe\Forms\EmailField;
use SilverStripe\Security\Security;
use LeKoala\CmsActions\CustomAction;
use SilverStripe\Control\Controller;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\Validation\RequiredFieldsValidator;
use SilverStripe\Security\Permission;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\RandomGenerator;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Class UserInvitation
 * @package Dynamic
 * @subpackage UserInvitation
 *
 * @property string $FirstName
 * @property string $Email
 * @property string $TempHash
 * @property string $Groups
 * @property int $InvitedByID
 * @method Member InvitedBy()
 *
 */
class UserInvitation extends DataObject
{
    /**
     * @config
     */
    private static $table_name = "UserInvitation";

    /**
     * Used to control whether a group selection on the invitation form is required.
     * @var bool
     * @config
     */
    private static $force_require_group = false;

    /**
     * Sender address for invitation emails. Falls back to Email.admin_email when empty.
     * If both are empty, sendInvitation() throws a RuntimeException.
     * @config
     */
    private static string $from_email = '';

    /**
     * Subject line for invitation emails. Falls back to the translatable default when empty.
     * @config
     */
    private static string $email_subject = '';

    /**
     * @config
     */
    private static $db = [
        'FirstName' => 'Varchar',
        'Email' => 'Varchar(254)',
        'TempHash' => 'Varchar',
        'Groups' => 'Text'
    ];

    /**
     * @config
     */
    private static $has_one = [
        'InvitedBy' => Member::class
    ];

    /**
     * @config
     */
    private static $indexes = [
        'Email' => true,
        'TempHash' => true
    ];

    /**
     * Removes the hash field from the list.
     * @return \SilverStripe\Forms\FieldList
     */
    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['TempHash']);

        $groups = Group::get()->map('Code', 'Title')->toArray();

        $fields->addFieldsToTab('Root.Main', [
            CheckboxSetField::create(
                'Groups',
                _t('UserController.INVITE_GROUP', 'Add to group'),
                $groups
            )
        ]);

        $fields->replaceField('Email', EmailField::create('Email'));

        $fields->addFieldToTab('Root.Main', ReadonlyField::create('TempHash'));
        $fields->replaceField(
            'InvitedByID',
            $fields->dataFieldByName('InvitedByID')->performReadonlyTransformation()
        );
        return $fields;
    }

    public function onBeforeWrite()
    {
        if (!$this->ID) {
            $generator = new RandomGenerator();
            $this->TempHash = $generator->randomToken('sha1');

            if (Security::getCurrentUser()) {
                $currentUserID = Member::get()->byID(Security::getCurrentUser()->ID);
                if ($currentUserID) {
                    $this->InvitedByID = $currentUserID->ID;
                }
            }
        }
        parent::onBeforeWrite();
    }

    /**
     * Resolves the From address for invitation emails.
     * Prefers UserInvitation.from_email, falls back to Email.admin_email.
     * @throws \RuntimeException if neither config value is set
     */
    private function resolveFromEmail(): string
    {
        $from = (string)(self::config()->get('from_email') ?: Email::config()->get('admin_email'));
        if (empty($from)) {
            throw new \RuntimeException(
                'UserInvitation: set UserInvitation.from_email or Email.admin_email before sending invitations.'
            );
        }
        return $from;
    }

    /**
     * Sends an invitation to the desired user
     */
    public function sendInvitation()
    {
        $siteConfig = SiteConfig::current_site_config();
        $subject = self::config()->get('email_subject')
            ?: _t(
                'UserInvitation.EMAIL_SUBJECT',
                'Invitation from {name}',
                ['name' => $this->InvitedBy()?->FirstName ?? '']
            );

        $email = Email::create()
            ->setFrom($this->resolveFromEmail())
            ->setTo($this->Email)
            ->setSubject($subject)
            ->setHTMLTemplate('email/UserInvitationEmail')
            ->setPlainTemplate('email/UserInvitationEmail_plain')
            ->setData([
                'Invite'      => $this,
                'SiteName'    => $siteConfig->Title,
                'AcceptLink'  => $this->getInvitationLink(),
                'InviteeName' => $this->FirstName,
                'InviterName' => $this->InvitedBy()?->FirstName ?? '',
                'ExpiryDate'  => $this->getExpiryDate(),
                'ExpiryDays'  => (int) self::config()->get('days_to_expiry'),
            ]);

        $this->extend('updateInvitationEmail', $email);

        $email->send();

        return $email;
    }

    /**
     * Returns the human-readable expiry date for this invitation.
     */
    public function getExpiryDate(): string
    {
        $days = (int) self::config()->get('days_to_expiry');
        $expiryTimestamp = strtotime((string) $this->LastEdited) + ($days * 86400);
        return date('F j, Y', $expiryTimestamp);
    }

    public function getCMSValidator()
    {
        return RequiredFieldsValidator::create([
            'FirstName',
            'Email'
        ]);
    }

    /**
     * Checks if a user invite was already sent, or if a user is already a member
     * @return ValidationResult
     */
    public function validate(): ValidationResult
    {
        $valid = parent::validate();
        $exists = $this->isInDB();

        if (!$exists) {
            if (self::get()->filter('Email', $this->Email)->first()) {
                // UserInvitation already sent
                $valid->addError(_t('UserInvitation.INVITE_ALREADY_SENT', 'This user was already sent an invite.'));
            }

            if (Member::get()->filter('Email', $this->Email)->first()) {
                // Member already exists
                $valid->addError(_t(
                    'UserInvitation.MEMBER_ALREADY_EXISTS',
                    'This person is already a member of this system.'
                ));
            }
        }
        return $valid;
    }

    /**
     * Checks if this invitation has expired
     * @return bool
     */
    public function isExpired()
    {
        $result = false;
        $days = self::config()->get('days_to_expiry');
        $time = DBDatetime::now()->getTimestamp();
        $ago = abs($time - strtotime($this->LastEdited));
        $rounded = round($ago / 86400);
        if ($rounded > $days) {
            $result = true;
        }
        return $result;
    }

    public function canCreate($member = null, $context = null)
    {
        return Permission::check('ACCESS_USER_INVITATIONS');
    }
    public function getCMSActions()
    {
        $actions = parent::getCMSActions();

        if ($this->isInDB()) {
            $actions->push(new CustomAction(
                "doCustomActionSendInvitation",
                _t(
                    'UserInvitation.SendInvitation',
                    'Send invitation'
                )
            ));
        } else {
            $actions->push(
                LiteralField::create(
                    'doCustomActionSendInvitationUnavailable',
                    "<span class=\"bb-align\">" . _t(
                        'UserInvitation.CreateSaveBeforeSending',
                        'Create/Save before sending invite!'
                    ) . "</span>"
                )
            );
        }

        return $actions;
    }

    public function doCustomActionSendInvitation()
    {

        if ($email = $this->sendInvitation()) {
            return $email;
        }

        return 'Invite was NOT send';
    }

    public function getInvitationLink()
    {
        return Controller::join_links(Director::AbsoluteBaseURL(), 'user', 'accept', $this->TempHash);
    }
}
