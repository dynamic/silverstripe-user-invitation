<?php

namespace Dynamic\SilverStripe\UserInvitations\Tests;

use SilverStripe\Dev\Debug;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Config\Config;
use Dynamic\SilverStripe\UserInvitations\Model\UserInvitation;

class UserInvitationTest extends SapphireTest
{
    protected static $fixture_file = 'UserInvitationTest.yml';

    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * Tests that sendInvitation() uses UserInvitation.from_email when configured.
     */
    public function testSendInvitationUsesFromEmailConfig(): void
    {
        Config::inst()->set(UserInvitation::class, 'from_email', 'invites@example.org');
        Config::inst()->set(Email::class, 'admin_email', '');

        /** @var UserInvitation $joe */
        $joe = $this->objFromFixture(UserInvitation::class, 'joe');
        $sent = $joe->sendInvitation();

        $from = $sent->getFrom();
        $this->assertCount(1, $from);
        $this->assertEquals('invites@example.org', $from[0]->getAddress());
    }

    /**
     * Tests that sendInvitation() falls back to Email.admin_email when from_email is not set.
     */
    public function testSendInvitationFallsBackToAdminEmail(): void
    {
        Config::inst()->set(UserInvitation::class, 'from_email', '');
        Config::inst()->set(Email::class, 'admin_email', 'noreply@example.org');

        /** @var UserInvitation $joe */
        $joe = $this->objFromFixture(UserInvitation::class, 'joe');
        $sent = $joe->sendInvitation();

        $from = $sent->getFrom();
        $this->assertCount(1, $from);
        $this->assertEquals('noreply@example.org', $from[0]->getAddress());
    }

    /**
     * Tests that sendInvitation() throws a RuntimeException when neither address is configured.
     */
    public function testSendInvitationThrowsWhenNoFromConfigured(): void
    {
        Config::inst()->set(UserInvitation::class, 'from_email', '');
        Config::inst()->set(Email::class, 'admin_email', '');

        /** @var UserInvitation $joe */
        $joe = $this->objFromFixture(UserInvitation::class, 'joe');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/from_email.*admin_email/');
        $joe->sendInvitation();
    }

    /**
     * Tests for expired invitations
     */
    public function testIsExpired()
    {
        /** @var UserInvitation $expired */
        $expired = $this->objFromFixture(UserInvitation::class, 'expired');
        $this->assertTrue($expired->isExpired());
    }

    /**
     * Tests that the TempHash field is readonly
     */
    public function testGetCMSFields()
    {
        /** @var UserInvitation $joe */
        $joe = $this->objFromFixture(UserInvitation::class, 'joe');
        $fields = $joe->getCMSFields();
        $tempHashField = $fields->dataFieldByName('TempHash');
        $this->assertNotNull($tempHashField);
        $this->assertTrue($tempHashField->isReadonly());
        $this->assertNotNull($fields->dataFieldByName('FirstName'));
        $this->assertNotNull($fields->dataFieldByName('Email'));
    }

    /**
     * Tests that invitations can't be re-sent.
     */
    public function testInvitationAlreadySent()
    {
        $invite = UserInvitation::create([
            'FirstName' => 'Joe',
            'Email' => 'joe@soap.person'
        ]);
        $result = $invite->validate();
        $this->assertFalse($result->isValid());
        $this->assertEquals('This user was already sent an invite.', $result->getMessages()[0]['message']);
    }

    /**
     * Tests that duplicate members can't be created
     */
    public function testMemberAlreadyExists()
    {
        $invite = UserInvitation::create([
            'FirstName' => 'Jane',
            'Email' => 'jane@doe.clone'
        ]);
        $result = $invite->validate();
        $this->assertFalse($result->isValid());
        $this->assertEquals('This person is already a member of this system.', $result->getMessages()[0]['message']);
    }

    /**
     * Tests that a random hash and the logged in users id was added
     */
    public function testOnBeforeWrite()
    {
        $this->logInWithPermission('ADMIN');
        $invite = UserInvitation::create([
            'FirstName' => 'Dane',
            'Email' => 'dane@example.com'
        ]);
        $invite->write();
        $this->assertNotNull($invite->TempHash);
        $this->assertNotNull($invite->InvitedByID);
    }

    /**
     * Tests that the invitation URL is correctly generated
     */
    public function testGetInvitationLink()
    {
        /** @var UserInvitation $joe */
        $joe = $this->objFromFixture(UserInvitation::class, 'joe');

        $link = $joe->getInvitationLink();

        // Should contain the base URL path
        $this->assertStringContainsString('/user/accept/', $link);

        // Should contain the TempHash
        $this->assertStringContainsString($joe->TempHash, $link);

        // Should be a valid URL format
        $this->assertMatchesRegularExpression('#^https?://.+/user/accept/[a-f0-9]+$#', $link);
    }
}
