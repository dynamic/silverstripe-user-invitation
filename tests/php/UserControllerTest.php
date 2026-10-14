<?php

namespace Dynamic\SilverStripe\UserInvitations\Tests;

use SilverStripe\Control\Email\Email;
use SilverStripe\Forms\Form;
use SilverStripe\Core\Convert;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Control\Director;
use SilverStripe\Security\Security;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\View\SSViewer;
use Dynamic\SilverStripe\UserInvitations\Model\UserInvitation;
use Dynamic\SilverStripe\UserInvitations\Control\UserController;

class UserControllerTest extends FunctionalTest
{
    protected static $fixture_file = 'UserControllerTest.yml';

    /**
     * @var UserController $controller
     */
    private $controller;

    /**
     * Theme whose templates stand in for a site theme during test runs.
     *
     * UserController::renderWithLayout() wraps the module's layout template in the theme's
     * `Page` shell. A consuming site gets that from its theme; a plain checkout of this
     * module has no theme at all, so a minimal shell is registered from tests/fixtures
     * instead of shipping a `templates/Page.ss` that would leak into every site's template
     * lookup.
     */
    protected function setUp(): void
    {
        parent::setUp();
        // Prepended rather than replacing: a site that runs this module's tests from within
        // its own project keeps its own themes (and the Page.ss they provide) for
        // everything the module does not render.
        SSViewer::set_themes(array_merge([$this->testThemePath()], SSViewer::get_themes()));
        $this->autoFollowRedirection = false;
        $this->logInWithPermission('ADMIN');
        $this->controller = new UserController();
    }

    protected function tearDown(): void
    {
        // SSViewer::set_themes() overrides a static, so clear it rather than rely on the
        // next test's setUp() to do it. An empty override makes get_themes() fall back to
        // the configured themes, i.e. to whatever was in effect before setUp().
        SSViewer::set_themes([]);
        parent::tearDown();
    }

    /**
     * Absolute path to the test theme, converted to the root-relative form SSViewer's
     * theme identifiers expect (see ThemeResourceLoader::getPath()).
     *
     * Derived from __DIR__ so it resolves to this module's fixtures both when the module is
     * checked out as its own project root and when it is installed under vendor/ inside a
     * site - a hard-coded '/tests/fixtures/...' would only be correct in the first case.
     */
    private function testThemePath(): string
    {
        $dir = dirname(__DIR__) . '/fixtures/TestTheme';
        $root = rtrim(BASE_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (str_starts_with($dir, $root)) {
            $dir = '/' . str_replace(DIRECTORY_SEPARATOR, '/', substr($dir, strlen($root)));
        }
        return $dir;
    }

    private function logoutMember()
    {
        Security::setCurrentUser(null);
    }

    /**
     * Tests redirected to a login screen if you're logged out.
     */
    public function testCantAccessWhenLoggedOut()
    {
        $this->logOut();
        $response = $this->get($this->controller->Link('index'));
        $this->assertFalse($response->isError());
        $this->assertEquals(302, $response->getStatusCode());
        $this->autoFollowRedirection = true;
    }

    /**
     * Tests that a user can access other actions
     */
    public function testCanAccessWhenLoggedOut()
    {
        $this->markTestSkipped();
        $this->logOut();
        $response = $this->get($this->controller->Link('accept'));
        $this->assertFalse($response->isError());
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * Tests that you can't access the index action without the requisite permission
     */
    public function testCantAccessWithoutPermission()
    {
        $this->loginInAsSomeone('john');
        $response = $this->get($this->controller->Link('index'));
        $this->assertEquals(403, $response->getStatusCode());
        $this->autoFollowRedirection = true;
    }

    /**
     * Tests if a form is returned and that expected fields are present
     */
    public function testInvitationForm()
    {
        $form = $this->controller->InvitationForm();
        $this->assertInstanceOf(Form::class, $form);
        $this->assertNotNull($form->Fields()->fieldByName('FirstName'));
        $this->assertNotNull($form->Fields()->fieldByName('Email'));
        $this->assertNotNull($form->Fields()->fieldByName('Groups'));
    }

    /**
     * Tests whether an email is sent and that an invitation record is created
     */
    public function testSendInvite()
    {
        $this->loginInAsSomeone('jane');
        /** @var Form $form */
        $data = $this->invitationData();
        Config::inst()->set(
            Email::class,
            'admin_email',
            'noreply@dynamicagency.com'
        );
        $response = $this->controller->sendInvite(
            $data,
            $this->controller->InvitationForm()->loadDataFrom($data)
        );
        $invitation = UserInvitation::get()->filter('Email', $data['Email']);
        $this->assertCount(1, $invitation);
        /** @var UserInvitation $invitation */
        $joe = $invitation->first();
        $this->assertEquals('Joe', $joe->FirstName);
        $this->assertEquals('joe@example.com', $joe->Email);
        $this->assertEquals('["test1","test2"]', $joe->Groups);
        $this->assertEquals(302, $response->getStatusCode());
    }

    public function invitationData()
    {
        return [
            'FirstName' => 'Joe',
            'Email' => 'joe@example.com',
            'Groups' => ['test1', 'test2']
        ];
    }

    /**
     * Tests that no invitation gets sent out without requisite permissions.
     */
    public function testSendInvitePermissionDenied()
    {
        $this->loginInAsSomeone('john');
        /** @var Form $form */
        $data = $this->invitationData();
        $response = $this->controller->sendInvite(
            $data,
            $this->controller->InvitationForm()->loadDataFrom($data)
        );
        $invitation = UserInvitation::get()->filter('Email', $data['Email']);
        $this->assertCount(0, $invitation);
        $this->assertEquals(302, $response->getStatusCode());
    }

    /**
     * Tests whether switching the UserInvitation::force_require_group has an effect or not
     */
    public function testGroupFieldNotRequired()
    {
        $this->loginInAsSomeone('jane');
        $data = [
            'FirstName' => 'Joe',
            'Email' => 'joe@example.com',
            'Groups' => ['test1', 'test2']
        ];
        $form = $this->controller->InvitationForm()->loadDataFrom($data);
        $this->assertTrue($form->getValidator()->getResult()->isValid());

        Config::inst()->set(
            UserInvitation::class,
            'force_require_group',
            true
        );
        unset($data['Groups']);
        $data['Groups'] = null;
        // Need to create a new form after config change
        $newController = new UserController();
        $form = $newController->InvitationForm()->loadDataFrom($data);
        $validator = $form->getValidator();
        $validator->php($data);
        $this->assertFalse($validator->getResult()->isValid());
    }

    private function loginInAsSomeone($name)
    {
        /** @var Member $member */
        $member = $this->objFromFixture(Member::class, $name);
        $this->logInAs($member);
    }

    /**
     * Tests for 403 if no ID parameter given
     */
    public function testAcceptForbiddenError()
    {
        $response = $this->get($this->controller->Link('accept'));
        $this->assertEquals(403, $response->getStatusCode());
    }

    /**
     * Tests for expired TempHash and that it redirects to the expired page
     */
    public function testAcceptExpiredTempHash()
    {
        /** @var UserInvitation $invitation */
        $invitation = $this->objFromFixture(UserInvitation::class, 'expired');
        $response = $this->get($this->controller->Link('accept/' . $invitation->TempHash));
        $this->assertEquals(302, $response->getStatusCode());
        $base = Director::absoluteBaseURL();
        $this->assertEquals(
            '/user/expired',
            str_replace($base, '', $response->getHeader('Location'))
        );
    }

    /**
     * Tests if a form is returned and that expected fields are present
     */
    public function testAcceptForm()
    {
        $form = $this->controller->AcceptForm();
        $this->assertInstanceOf(Form::class, $form);
        $this->assertNotNull($form->Fields()->fieldByName('FirstName'));
        $this->assertNull($form->Fields()->fieldByName('Email'));
        $this->assertNotNull($form->Fields()->fieldByName('HashID'));
        $this->assertNotNull($form->Fields()->fieldByName('Surname'));
    }

    /**
     * The Password field should carry help text by default, since the site's password
     * validator is typically strength-based rather than a fixed checklist.
     */
    public function testAcceptFormPasswordDescription()
    {
        $form = $this->controller->AcceptForm();
        $passwordField = $form->Fields()->dataFieldByName('Password');
        $this->assertNotNull($passwordField);
        $this->assertNotEmpty($passwordField->getDescription());
    }

    /**
     * Project config can override or suppress the default password help text.
     */
    public function testAcceptFormPasswordDescriptionConfigurable()
    {
        Config::modify()->set(UserController::class, 'password_description', 'Custom help text');
        $passwordField = $this->controller->AcceptForm()->Fields()->dataFieldByName('Password');
        $this->assertSame('Custom help text', $passwordField->getDescription());

        // A single-character "0" is falsy in PHP but a legitimate configured value; it
        // must be kept, not silently dropped by a truthiness check.
        Config::modify()->set(UserController::class, 'password_description', '0');
        $passwordField = $this->controller->AcceptForm()->Fields()->dataFieldByName('Password');
        $this->assertSame('0', $passwordField->getDescription());

        Config::modify()->set(UserController::class, 'password_description', '');
        $passwordField = $this->controller->AcceptForm()->Fields()->dataFieldByName('Password');
        $this->assertEmpty($passwordField->getDescription());

        // Explicitly setting null is indistinguishable from leaving it unconfigured, so
        // it falls back to the built-in default rather than suppressing the text - only
        // an empty string suppresses it. This locks in that documented behavior.
        Config::modify()->set(UserController::class, 'password_description', null);
        $passwordField = $this->controller->AcceptForm()->Fields()->dataFieldByName('Password');
        $this->assertNotEmpty($passwordField->getDescription());
    }

    /**
     * Tests that redirected to not found if has not found
     */
    public function testSaveInviteWrongHashError()
    {
        $data = [
            'HashID' => '432'
        ];
        $response = $this->controller->saveInvite(
            $data,
            $this->controller->AcceptForm()->loadDataFrom($data)
        );
        $this->assertEquals(302, $response->getStatusCode());
        $base = Director::absoluteBaseURL();
        $this->assertEquals(
            '/user/notfound',
            str_replace($base, '', $response->getHeader('Location'))
        );
    }

    public function testSaveInvite()
    {
        /** @var UserInvitation $invitation */
        $invitation = $this->objFromFixture(UserInvitation::class, 'joe');
        $data = [
            'HashID' => $invitation->TempHash,
            'FirstName' => $invitation->FirstName,
            'Surname' => 'Soap',
            'Password' => [
                '_Password' => 'password',
                '_ConfirmPassword' => 'password'
            ]
        ];

        $response = $this->controller->saveInvite(
            $data,
            $this->controller->AcceptForm()->loadDataFrom($data)
        );
        $this->assertEquals(302, $response->getStatusCode());
        $base = Director::absoluteBaseURL();
        $this->assertEquals(
            '/user/success',
            str_replace($base, '', $response->getHeader('Location'))
        );

        // Assert that invitation is deleted
        $this->assertNull(UserInvitation::get()->filter(
            'Email',
            $invitation->Email
        )->first());

        /** @var Member $joe */
        $joe = Member::get()->filter('Email', $invitation->Email)->first();
        // Assert that member is created
        $this->assertTrue($joe->exists());

        // Assert that member belongs to the groups selected
        $this->assertTrue($joe->inGroup($this->objFromFixture(
            Group::class,
            'test1'
        )));
        $this->assertTrue($joe->inGroup($this->objFromFixture(
            Group::class,
            'test2'
        )));
    }

    /**
     * Tests that a login link is presented to the user
     */
    public function testSuccess()
    {
        $this->logoutMember();
        $response = $this->get($this->controller->Link('success'));
        $body = Convert::nl2os($response->getBody(), '');
        //$this->assertContains('Congratulations!', $body);
        $this->assertStringContainsString('You are now a registered member', $body);
        $security = Injector::inst()->get(Security::class);
        $link = $security->Link('login');
        $this->assertStringContainsString("<a href=\"{$link}\">", $body);
    }

    /**
     * Tests that the expired action is shown
     */
    public function testExpired()
    {
        $this->logoutMember();
        $response = $this->get($this->controller->Link('expired'));
        $body = Convert::nl2os($response->getBody(), '');
        $this->assertStringContainsString('Invitation expired', $body);
        $this->assertStringContainsString(
            'Oops, you took too long to accept this invitation',
            $body
        );
    }

    /**
     * Tests that the notfound action is shown.
     */
    public function testNotFound()
    {
        $this->logoutMember();
        $response = $this->get($this->controller->Link('notfound'));
        $body = Convert::nl2os($response->getBody(), '');
        $this->assertStringContainsString('Invitation not found', $body);
        $this->assertStringContainsString('Oops, the invitation ID was not found.', $body);
    }

    /**
     * Tests whether links are correctly re-written.
     */
    public function testLink()
    {
        $this->assertEquals('user/accept', $this->controller->Link('accept'));
        $this->assertEquals('user/index', $this->controller->Link('index'));
    }

    /**
     * Tests that the array key is returned
     */
    public function testProvidePermissions()
    {
        $permissions = $this->controller->providePermissions();
        $this->assertArrayHasKey('ACCESS_USER_INVITATIONS', $permissions);
        $this->assertArrayHasKey(
            'name',
            $permissions['ACCESS_USER_INVITATIONS']
        );
        $this->assertArrayHasKey(
            'category',
            $permissions['ACCESS_USER_INVITATIONS']
        );
    }

    /**
     *
     */
    public function testRenderWithLayout()
    {
        $render = $this->controller->renderWithLayout([]);
        $this->assertInstanceOf(DBHTMLText::class, $render);
    }

    /**
     *
     */
    public function testGetLayoutTemplates()
    {
        // test string to template array
        $templates = $this->controller->getLayoutTemplates('NewPage');
        $this->assertEquals([
            'type' => 'Layout',
            'NewPage',
            'Page',
        ], $templates);

        // test empty array to template array
        $templates = $this->controller->getLayoutTemplates([]);
        $this->assertEquals([
            'type' => 'Layout',
            'Page',
        ], $templates);

        // test empty array to template array
        $templates = $this->controller->getLayoutTemplates(['NewPage']);
        $this->assertEquals([
            'type' => 'Layout',
            'NewPage',
            'Page',
        ], $templates);
    }
}
