<?php
/**
 * Database User Test File
 *
 *
 * License:
 *
 * @author Tom Laudeman
 * @license http://opensource.org/licenses/BSD-3-Clause BSD 3-Clause
 * @copyright 2015 the Rector and Visitors of the University of Virginia, and
 *            the Regents of the University of California
 */

/**
 * Database User test suite
 * 
 * @author Tom Laudeman
 *
 */
class DBUserTest extends PHPUnit_Framework_TestCase 
{
    /**
     * DBUser object for this class
     * @var $dbu \snac\server\database\DBUser object
     */ 
    private $dbu = null;


    /**
     * Constructor
     *
     * Note about how things are different here in testing world vs normal execution:
     * 
     * Any vars that aren't set up in the constructor won't be initialized, even though the other functions
     * appear to run in order. Initializing instance vars anywhere except the constructor does not initialize
     * for the whole class. phpunit behaves as though the class where being instantiated from scratch for each
     * test.
     *
     * In cases where tests need to happen in order, all the ordered tests are most easily done inside one
     * test, with multiple assertions.
     */ 
    public function __construct() 
    {
        $this->dbu = new snac\server\database\DBUser();
        // Prototypeing..
        // $this->traverseHead();
        // exit();
    }

    
    /**
     * {@inheritDoc}
     * @see PHPUnit_Framework_TestCase::setUp()
     *
     * This is run before each test, not just once before all tests.
     */
    public function setUp() 
    {
        /*
         * Start by deleting the test account, if it exists. We leave the old user after a test for debugging purposes.
         *
         * We do not want to leave the 'demo' role, but failures errors can cause that. So also delete the demo role, if it exists.
         */ 
        $testUser = new \snac\data\User();
        $testUser->setUserName("mst3k@example.com");
        $this->user = $this->dbu->readUser($testUser);
        
        if ($oldUser = $this->dbu->readUser($testUser))
        {
            $appUserID = $oldUser->getUserID();
            $oldDemoRole = $this->dbu->checkRoleByLabel($oldUser, 'demo');
            if ($oldDemoRole)
            {
                $this->dbu->eraseRoleByID($oldDemoRole->getID());
            }
            if ($appUserID)
            {
                $this->dbu->clearAllSessions($oldUser);
                $this->dbu->eraseUser($oldUser);
            }
        }
        

    }

    public function testBasic()
    {
        /*
         * Create a new user.
         */ 
        $userObj = new \snac\data\User();
        $userObj->setFirstName("Malf");
        $userObj->setLastName("Torrent");
        $userObj->setFullName("Malf S Torrent");
        $userObj->setAvatar("http://example.com/avatar");
        $userObj->setAvatarSmall("http://example.com/avatar_small");
        $userObj->setAvatarLarge("http://example.com/avatar_large");
        $userObj->setEmail("mst3k@example.com");
        $newUser = $this->dbu->createUser($userObj);

        $this->assertNotNull($newUser);

        /*
         * Update the user in order to exercise updateUser()
         */
        $newUser->setFirstName('Malvie');
        $this->dbu->saveUser($newUser);
        $newUser = $this->dbu->readUser($newUser);
        $this->assertEquals('Malvie', $newUser->getFirstName());

        /*
         * Try adding a password. Yes, I know this password is not hashed.
         */ 
        $this->dbu->writePassword($newUser, 'foobarbaz');
        $this->assertTrue($this->dbu->checkPassword($newUser, 'foobarbaz'));

        /*
         * Add a role to our new user. Really, the db should be initialized with a 'researcher' or
         * 'contributor' role.
         */ 
        $roleObjList = $this->dbu->roleList();
        foreach($roleObjList as $roleObj)
        {
            if ($roleObj->getLabel() == 'system')
            {
                $this->dbu->addUserRole($newUser, $roleObj);
                break;
            }
        }
        /*
         * We might add a default role (not necessarily 'Public HRT'), so even during testing we cannot assume
         * that role[0] is 'system'.
         */ 
        $roleList = $this->dbu->listUserRole($newUser);
        /* 
         * $foundSystem = false;
         * $systemRole = null;
         * foreach($roleList as $role)
         * {
         *     if ($role->getLabel() == 'system')
         *     {
         *         $foundSystem = true;
         *         $systemRole = $role;
         *     }
         * }
         */
        $systemRole = $this->dbu->checkRoleByLabel($newUser, 'system');
        // false == null, so we only need to check for != null.
        $this->assertTrue($systemRole != null);

        /*
         * Write out the user object as for review.
         */ 
        /* 
         * $cfile = fopen('user_object.txt', 'w');
         * fwrite($cfile, var_export($newUser, 1));
         * fclose($cfile);
         */

        /*
         * Remove the role 'system' from our user, and count. User might always have a default role which we
         * should not remove.
         *
         * Rather than rely on an index, the code above saves the system role in a variable, and we use that
         * variable here.
         */ 
        // $this->dbu->removeUserRole($newUser, $roleList[0]);
        $this->dbu->removeUserRole($newUser, $systemRole);
        $roleList = $this->dbu->listUserRole($newUser);
        $this->assertEquals(count($roleList), 0);

        /*
         * Create a new role, add it, check that our user has the role. Normally, roles probably aren't deleted, but we want to
         * delete the temp role as part of cleaning up.
         */ 
        $demoRole = $this->dbu->createRole('demo', 'Demo role created during testing');
        $this->dbu->addUserRole($newUser, $demoRole);
        $roleList = $this->dbu->listUserRole($newUser);
        $preCleaningRoleList = $this->dbu->roleList();

        // false == null so we only check for != null
        $this->assertTrue($this->dbu->checkRoleByLabel($newUser, 'demo') != null);

        /* 
         * Clean up, some. Remove the role from our user, delete the role. The make sure our user is back to
         * the same number of roles as before.
         */
        $this->dbu->removeUserRole($newUser, $demoRole);
        $this->dbu->eraseRoleByID($demoRole->getID());
        $postCleaningRoleList = $this->dbu->roleList();
        $this->assertEquals(count($preCleaningRoleList), count($postCleaningRoleList)+1);

        /*
         * Uncomment the lines below to check the user-does-not-exist case. Later, make this a real test.
         */ 
        /* 
         * printf("\ndbusertest deleting appuserid: %s\n", $newUser->getUserID());
         * $this->dbu->eraseUser($newUser);
         */


        /*
         * This is clearly a lame session, which is the point of the test.  If the user is not cleaned up, the
         * old test session will linger. So, before testing session code remove any copies of session 'foo'.
         */
         $newUser->setToken(array('access_token' => 'foo',
                                  'expires' => 12345));
         $this->dbu->removeSession($newUser);
         if (! $this->dbu->sessionExists($newUser))
         {
             $this->dbu->addSession($newUser);
         }
         
         /*
          * The expire time is in the past, so this session is not active.
          */
         $this->assertFalse($this->dbu->sessionActive($newUser));
         
         /*
          * Extend the session into the future
          */ 
         $this->dbu->sessionExtend($newUser, time() + (60*60) + 10);
         $this->assertTrue($this->dbu->sessionActive($newUser));

        /*
         * When things are normally successful, we will clean up.  Or not. If we don't clean up, then we can
         * use psql to look at the database.
         */ 
        // $this->dbu->eraseUser($newUser);

    }

    public function testAutoUser()
    {
        /*
         * Create a new user object
         */ 
        $userObj = new \snac\data\User();
        $userObj->setFirstName("Malf");
        $userObj->setLastName("Torrent");
        $userObj->setFullName("Malf S Torrent");
        $userObj->setAvatar("http://example.com/avatar");
        $userObj->setAvatarSmall("http://example.com/avatar_small");
        $userObj->setAvatarLarge("http://example.com/avatar_large");
        $userObj->setEmail("mst3k@example.com");
        
        /*
         * User does not exist in db.
         *
         * This is a more or less valid session, with an expires 1 hour in the future.
         */ 
        $userObj->setToken(array('access_token' => 'foo',
                                 'expires' => time() + (60*60)));
        $csaReturn = $this->dbu->createUser($userObj);
        $this->dbu->removeSession($csaReturn);
         if (! $this->dbu->sessionExists($csaReturn))
         {
             $this->dbu->addSession($csaReturn);
         }
        $this->assertEquals($csaReturn->getToken()['access_token'], 'foo');
        $this->assertTrue($this->dbu->sessionActive($csaReturn));

        /*
         * We don't have default role, but if we did this would verify that we got the default role of Public
         * HRT.
         *
         * false == null so we only check for != null
         * We don't current have a role for public hrt.
         * $this->assertTrue($this->dbu->checkRoleByLabel($csaReturn, 'Public HRT') != null);
         */

        $goodUserID = $csaReturn->getUserID();
        /*
         * Test userExists() with the ficticious user id.
         */
        $csaReturn->setUserID('123456');
        $this->assertFalse($this->dbu->userExists($csaReturn));

        /*
         * When things are normally successful, we might want to clean up.  Or not. If we don't clean up, then
         * we can use psql to look at the database.
         */ 
        // $this->dbu->eraseUser($newUser);

    }

    
}
