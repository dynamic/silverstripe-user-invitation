<?php

namespace Dynamic\SilverStripe\UserInvitations\Tests;

use SilverStripe\Control\Email\Email;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
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
use SilverStripe\Core\Manifest\ModuleLoader;
use SilverStripe\Core\Path;
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
        // Prepended, not replacing: the fixture shell wins for `Page`, and
        // a site running these tests from inside its own project keeps its own themes for
        // every other template. Framework's SSViewerTestState clears the override before each
        // test (framework/_config/tests.yml); this builds on top of that.
        SSViewer::set_themes(array_merge([$this->testThemePath()], SSViewer::get_themes()));
        $this->autoFollowRedirection = false;
        $this->logInWithPermission('ADMIN');
        $this->controller = new UserController();
    }

    protected function tearDown(): void
    {
        // SSViewer::set_themes() sets a static. SapphireTest's own state reset clears it
        // again before the next test; clearing it here too keeps this class from leaving a
        // theme override behind for anything else in the same process.
        SSViewer::set_themes([]);
        parent::tearDown();
    }

    /**
     * Path to the test theme, in the root-relative form SSViewer's theme identifiers expect
     * (see ThemeResourceLoader::getPath()).
     *
     * Derived from the module manifest rather than __DIR__, so it resolves to this module's
     * fixtures whether the module is checked out as its own project root, copied under
     * vendor/ inside a site, or symlinked there by a composer path repository - a hard-coded
     * '/tests/fixtures/...' would only be correct in the first case, and __DIR__ resolves
     * symlinks, which can land outside BASE_PATH and produce an identifier that points at
     * nothing. Fails loudly rather than returning such an identifier silently.
     */
    private function testThemePath(): string
    {
        $module = ModuleLoader::inst()->getManifest()->getModule('dynamic/silverstripe-user-invitation');
        if (!$module) {
            $this->fail(
                'The test theme could not be registered: this module is not in the module '
                . 'manifest, so its tests/fixtures/TestTheme has no path from the project root.'
            );
        }
        return '/' . ltrim(Path::join((string)$module->getRelativePath(), 'tests/fixtures/TestTheme'), '/');
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
     * Regression test: posting the accept form with an expired invitation must not create
     * a Member nor delete the invitation - it redirects to the expired page instead.
     */
    public function testSaveInviteExpired()
    {
        /** @var UserInvitation $invitation */
        $invitation = $this->objFromFixture(UserInvitation::class, 'expired');
        $this->assertTrue($invitation->isExpired());
        $data = [
            'HashID' => $invitation->TempHash,
            'FirstName' => $invitation->FirstName,
            'Surname' => 'Poke',
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
            '/user/expired',
            str_replace($base, '', $response->getHeader('Location'))
        );

        // No account may have been created from the expired invitation
        $this->assertNull(Member::get()->filter('Email', $invitation->Email)->first());

        // The invitation is left in place
        $this->assertNotNull(UserInvitation::get()->filter(
            'TempHash',
            $invitation->TempHash
        )->first());
    }

    /**
     * A missing, empty or non-string HashID is a clean "not found" redirect, never a fatal,
     * and never creates a Member.
     */
    public function testSaveInviteBadHashInput()
    {
        $base = Director::absoluteBaseURL();
        $memberCount = Member::get()->count();
        $cases = [
            'missing hash' => [],
            'empty hash' => ['HashID' => ''],
            'array hash' => ['HashID' => ['nope', 'still-nope']],
            'unknown hash' => ['HashID' => 'no-such-hash'],
        ];
        foreach ($cases as $label => $data) {
            // A fresh controller per case, so a redirect from one case cannot carry over
            // to the next one's response object.
            $controller = new UserController();
            $response = $controller->saveInvite(
                $data,
                $controller->AcceptForm()->loadDataFrom($data)
            );
            $this->assertEquals(302, $response->getStatusCode(), $label);
            $this->assertEquals(
                '/user/notfound',
                str_replace($base, '', $response->getHeader('Location')),
                $label
            );
        }
        $this->assertEquals($memberCount, Member::get()->count());
    }

    /**
     * The accept form must not be populated from an expired invitation.
     */
    public function testAcceptFormIgnoresExpiredInvite()
    {
        /** @var UserInvitation $invitation */
        $invitation = $this->objFromFixture(UserInvitation::class, 'expired');
        $this->setAcceptRequest($invitation->TempHash);

        $firstName = $this->controller->AcceptForm()->Fields()->dataFieldByName('FirstName');
        $this->assertNotNull($firstName);
        $this->assertEquals('', $firstName->getValue());
    }

    /**
     * The accept form is still populated for a valid, unexpired invitation.
     */
    public function testAcceptFormPopulatesValidInvite()
    {
        /** @var UserInvitation $invitation */
        $invitation = $this->objFromFixture(UserInvitation::class, 'joe');
        $this->setAcceptRequest($invitation->TempHash);

        $firstName = $this->controller->AcceptForm()->Fields()->dataFieldByName('FirstName');
        $this->assertNotNull($firstName);
        $this->assertEquals('Joe', $firstName->getValue());
    }

    /**
     * Point the controller at an accept request for the given invitation hash, so that
     * AcceptForm() resolves the invitation the same way it does on the accept route.
     *
     * @param string|null $hash
     * @return void
     */
    private function setAcceptRequest($hash)
    {
        $request = new HTTPRequest('GET', $this->controller->Link('accept') . '/' . $hash, []);
        $request->setSession(new Session([]));
        $request->setRouteParams(['Action' => 'accept', 'ID' => $hash]);
        $this->controller->setRequest($request);
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
     * A module layout template renders inside the theme's `Page` shell, not on its own.
     */
    public function testRenderWithLayout()
    {
        // Render one of the module's own layout templates rather than an empty list, so the
        // assertion has module markup to look for.
        $render = $this->controller->renderWithLayout(UserController::class);
        $this->assertInstanceOf(DBHTMLText::class, $render);
        // The layout comes from the module (Layout/UserController.ss), the shell from the
        // theme: the module's heading must sit inside the shell's <body> element.
        $this->assertMatchesRegularExpression(
            '/<body>.*User Invitation.*<\/body>/s',
            (string)$render,
            'The module layout did not render inside the theme shell'
        );
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
