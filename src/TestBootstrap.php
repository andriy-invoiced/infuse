<?php

require_once 'App.php';

use infuse\ErrorStack;
use infuse\Util;

use app\search\libs\SearchableModel;
use app\users\models\User;

class TestBootstrap implements PHPUnit_Framework_TestListener
{
	static $userEmail;
	static $userPassword = 'testpassword';
	private $verbose;
	private static $staticApp;
	private $app;

	public static function app( $id = false )
	{
		if( $id )
			return self::$staticApp[ $id ];
		else
			return self::$staticApp;
	}

	public function __construct( $verbose )
	{
		$config = @include 'config.php';

		$this->app = new App( $config );
		self::$staticApp = $this->app;

		$this->verbose = $verbose;

		// create a test user and login
		if( $this->verbose )
			echo "Logging in a test user to run the test suite.\n";

		self::$userEmail = 'test' . Util::guid() . '@exmaple.com';

		$user = new User;
		$success = $user->create( [
			'user_email' => self::$userEmail,
			'user_password' => [ self::$userPassword, self::$userPassword ],
			'first_name' => 'Bob',
			'ip' => '127.0.0.1' ] );

		if( $this->verbose )
		{
			if( $success )
				echo "User #" . $user->id() . " created.\n";
			else
				echo "Could not create test user.\n";
		}

		$loggedIn = $this->app[ 'auth' ]->login( self::$userEmail, self::$userPassword );
		
		if( $this->verbose )
		{
			if( $loggedIn )
				echo "User #" . $this->app[ 'user' ]->id() . " logged in.\n";
			else
				echo " Could not log test user in.\n";
		}

		// CUSTOM
		$this->app[ 'config' ]->set( 'email.type', 'nop' );
		if( class_exists( 'app\search\libs\SearchableModel' ) )
			SearchableModel::disableIndexing();
	}

	public function __destruct()
	{
		$user = $this->app[ 'user' ];
		$user->grantAllPermissions();
		$deleted = $user->delete();

		if( $this->verbose )
		{
			if( $deleted )
				echo "User #" . $user->id() . " deleted.\n";
			else
				echo "User #" . $user->id() . " NOT deleted.\n";				
		}
	}

	public function addError(PHPUnit_Framework_Test $test, Exception $e, $time)
	{
		if( $this->verbose )
			printf(" Error while running test '%s'.\n", $test->getName() );
	}

	public function addFailure(PHPUnit_Framework_Test $test, PHPUnit_Framework_AssertionFailedError $e, $time)
	{
		if( $this->verbose )
			printf( "Test '%s' failed.\n", $test->getName() );
	}

	public function addIncompleteTest(PHPUnit_Framework_Test $test, Exception $e, $time)
	{
		if( $this->verbose )
			printf( "Test '%s' is incomplete.\n", $test->getName() );
	}

	public function addSkippedTest(PHPUnit_Framework_Test $test, Exception $e, $time)
	{
		if( $this->verbose )
			printf( "Test '%s' has been skipped.\n", $test->getName() );
	}

	public function startTest(PHPUnit_Framework_Test $test)
	{
		if( $this->verbose )
			printf( "Test '%s' started.\n", $test->getName() );

		$this->app[ 'user' ]->disableSU();
	}

	public function endTest(PHPUnit_Framework_Test $test, $time)
	{
		if( $this->verbose )
			printf( "Test '%s' ended.\n", $test->getName() );
	}

	public function startTestSuite(PHPUnit_Framework_TestSuite $suite)
	{
		if( $this->verbose )
			printf( "TestSuite '%s' started.\n", $suite->getName() );
		else if( $suite->getName() != 'App' )
			printf( "\n\n%s:\n", $suite->getName() );
	}

	public function endTestSuite(PHPUnit_Framework_TestSuite $suite)
	{
		if( $this->verbose )
			printf( "TestSuite '%s' ended.\n", $suite->getName() );

		// nuke memcache in between test suites
		$config = $this->app[ 'config' ];
		if( $config->get( 'memcache.enabled' ) )
		{
			$memcache_obj = new Memcache;
			$memcache_obj->connect( $config->get( 'memcache.host' ), $config->get( 'memcache.port' ) );

			$memcache_obj->flush();
		}

		$errors = $this->app[ 'errors' ]->errors();

		if( count( $errors ) > 0 )
		{
			if( $this->verbose )
			{
				printf( "TestSuite '%s' produced these errors:\n", $suite->getName() );
				print_r( $errors );
			}
			$this->app[ 'errors' ]->clear();
		}
	}
}