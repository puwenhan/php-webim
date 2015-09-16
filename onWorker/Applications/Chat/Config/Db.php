<?php
/**
 * @author will <wizarot@gmail.com>
 * @link http://wizarot.me/
 *
 * Date: 15/9/16
 * Time: 下午4:14
 */
namespace Config;
/**
 * mysql配置
 * @author walkor
 */
class Db
{
    /**
     * 数据库的一个实例配置，则使用时像下面这样使用
     * $log_array = Db::instance('log')->select('name,age')->from('users')->where('age>12')->query();
     * 等价于
     * $log_array = Db::instance('log')->query('SELECT `name`,`age` FROM `users` WHERE `age`>12');
     * @var array
     */
    public static $log = array(
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'user'    => 'root',
        'password' => '',
        'dbname'  => 'wiz-cms',
        'charset'    => 'utf8',
    );
}