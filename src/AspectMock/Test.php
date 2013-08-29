<?php
namespace AspectMock;
use AspectMock\Core\Registry;
use AspectMock\Proxy\Anything;
use AspectMock\Proxy\AnythingClassProxy;
use AspectMock\Proxy\ClassProxy;
use AspectMock\Proxy\InstanceProxy;
use AspectMock\Proxy\Verifier;

/**
 * `AspectMock\Test` class is a builder of test doubles.
 * Any object can be enhanced and turned to a test double with the call to `double` method.
 * This allows to redefine any method of object with your own, and adds mock verification methods.
 *
 * **Recommended Usage**:
 *
 * ``` php
 * <?php
 * use AspectMock\Test as test;
 * ?>
 * ```
 */
class Test {

    /**
     * test::double registers class or object to track its calls.
     * In second argument you may pass values that mocked mathods should return.
     *
     * Returns either of [**ClassProxy**](https://github.com/Codeception/AspectMock/blob/master/docs/ClassProxy.md)
     * or [**InstanceProxy**](https://github.com/Codeception/AspectMock/blob/master/docs/InstanceProxy.md).
     * Proxies are used to verify method invocations, and some other useful things.
     *
     * Example:
     *
     * ``` php
     * <?php
     *
     * # simple
     * $user = test::double(new User, ['getName' => 'davert']);
     * $user->getName() // => davert
     * $user->verifyInvoked('getName'); // => success
     *
     * # with closure
     * $user = test::double(new User, ['getName' => function() { return $this->login; }]);
     * $user->login = 'davert';
     * $user->getName(); // => davert
     *
     * # on a class
     * $ar = test::double('ActiveRecord', ['save' => null]);
     * $user = new User;
     * $user->name = 'davert';
     * $user->save(); // passes to ActiveRecord->save() and does not insert any SQL.
     * $ar->verifyInvoked('save'); // true
     *
     * # on static method call
     * User::tableName(); // 'users'
     * $user = test::double('User', ['tableName' => 'fake_users']);
     * User::tableName(); // 'fake_users'
     * $user->verifyInvoked('tableName'); // success
     *
     * # tests a method returned the desired result.
     * $user = test::double(new User['name' => 'davert']);
     * $user->getName();
     * $user->verifyMethodInvoked('getName')->returned('davert');
     *
     * # append declaration
     * $user = new User;
     * test::double($user, ['getName' => 'davert']);
     * test::double($user, ['getEmail' => 'davert@mail.ua']);
     * $user->getName(); // => 'davert'
     * $user->getEmail(); => 'davert@mail.ua'
     *
     * # create an instance of mocked class
     * test::double('User')->construct(['name' => 'davert']); // via constructir
     * test::double('User')->make(); // without calling constructor
     * 
     * # stub for magic method
     * test::double('User', ['findByUsernameAndPasswordAndEmail' => false]);
     * User::findByUsernameAndPasswordAndEmail; // null
     * 
     * # stub for method of parent class
     * # if User extends ActiveRecord
     *
     * test::double('ActiveRecord', ['save' => false]);
     * $user = new User(['name' => 'davert']);
     * $user->save(); // false
     *
     * ?>
     * ```
     *
     * @api
     * @param $classOrObject
     * @param array $params
     * @throws \Exception
     * @return Verifier
     */
    public static function double($classOrObject, $params = array())
    {
        $classOrObject = Registry::getRealClassOrObject($classOrObject);
        if (is_string($classOrObject)) {
            if (!class_exists($classOrObject)) {
                throw new \Exception("Class $classOrObject not loaded.\nIf you want to test undefined class use 'test::spec' method");
            }
            Core\Registry::registerClass($classOrObject, $params);
            return new Proxy\ClassProxy($classOrObject);
        }
        if (is_object($classOrObject)) {
            Core\Registry::registerObject($classOrObject, $params);
            return new Proxy\InstanceProxy($classOrObject);
        }
    }

    /**
     * If you follow TDD/BDD practices a test should be written before the class is defined.
     * If you would call undefined class in a test, a fatal error will be triggered.
     * Instead you can use `test::spec` method that will create a proxy for an undefined class.
     *
     * ``` php
     * <?php
     * $userClass = test::spec('User');
     * $userClass->defined(); // false
     * ?>
     * ```
     *
     * You can create instances of undefined classes and play with them.
     *
     * ``` php
     * <?php
     * $user = test::spec('User')->construct();
     * $user->setName('davert');
     * $user->setNumPosts(count($user->getPosts()));
     * $this->assertEquals('davert', $user->getName()); // fail
     *
     * ?>
     * ```
     *
     * The test will be executed normally and will fail on the first assertion.
     *
     * `test::spec()->construct` creates an instance of `AspectMock\Proxy\Anything`
     * which tries to not cause errors whatever you try to do with it.
     *
     * ``` php
     * <?php
     * $user = test::spec('Undefined')->construct();
     * $user->can()->be->called()->anything();
     * $user->can['be used as array'];
     * foreach ($user->names as $name) {
     *      $name->canBeIterated();
     * }
     * ?>
     * ```
     *
     * None of this calls will trigger error on your test.
     * Thus, you can write a valid test before the class is declared.
     *
     * If class is already defined, `test::spec` will act as `test::double`.
     *
     * @api
     * @param $classOrObject
     * @param array $params
     * @return Verifier
     *
     */
    public static function spec($classOrObject, $params = array())
    {
        if (is_object($classOrObject)) return self::double($classOrObject, $params);
        if (class_exists($classOrObject)) return self::double($classOrObject, $params);
        
        return new AnythingClassProxy($classOrObject);
    }

    /**
     * Replaces all methods in a class with a dummies, except specified.
     *
     * ``` php
     * <?php
     * $user = new User(['name' => 'jon']);
     * test::methods($user, ['getName']);
     * $user->setName('davert'); // not invoked
     * $user->getName(); // jon
     * ?>
     * ```
     *
     * You can create a dummy without a constructor with all methods disabled
     *
     * ``` php
     * <?php
     * $user = test::double('User')->make();
     * test::methods($user, []);
     * ?>
     * ```
     *
     * @api
     * @param $classOrObject
     * @param array $only
     * @return Core\ClassProxy|Core\InstanceProxy
     * @throws \Exception
     */
    public static function methods($classOrObject, array $only = array())
    {
        $classOrObject = Registry::getRealClassOrObject($classOrObject);
        if (is_object($classOrObject)) {
            $reflected = new \ReflectionClass(get_class($classOrObject));
        } else {
            if (!class_exists($classOrObject)) throw new \Exception("Class $classOrObject not defined.");
            $reflected = new \ReflectionClass($classOrObject);
        }
        $methods = $reflected->getMethods(\ReflectionMethod::IS_PUBLIC);
        $params = array();
        foreach ($methods as $m) {
            if ($m->isConstructor()) continue;
            if ($m->isDestructor()) continue;
            if (in_array($m->name, $only)) continue;
            $params[$m->name] = null;
        }
        return self::double($classOrObject, $params);
    }

    /**
     * Clears test doubles registry.
     * Should be called between tests.
     *
     * ``` php
     * <?php
     * test::clean();
     * ?>
     * ```
     *
     * Also you can clean registry only for the specific class or object.
     *
     * ``` php
     * <?php
     * test::clean('User');
     * test::clean($user);
     * ?>
     * ```
     *
     * @api
     */
    public static function clean($classOrInstance = null)
    {
        Core\Registry::clean($classOrInstance);
    }

    /*
     * Clears mock verifications, but not stub definitions.
     *
     * @api
     */
    public static function cleanInvocations()
    {
        Core\Registry::cleanInvocations();
    }    


}
