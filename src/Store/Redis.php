<?php
namespace WebIM\Store;

class Redis
{
    /**
     * @var \redis
     */
    protected $redis;

    static $prefix = "webim_";

    function __construct($host = '127.0.0.1', $port = 6379, $timeout = 0.0)
    {
        $redis = new \redis;
        $redis->connect($host, $port, $timeout);
        $this->redis = $redis;
        // 重启服务,是否要清理记录?
    }

    // 记录料条记录
    function saveChatLog($user_id , $msg)
    {
        // list 数据记录
        $key = self::$prefix.'chatlog:client_'.$user_id;
        $this->redis->rpush($key , $msg);
    }




    function getOnlineUsers()
    {
        return $this->redis->sMembers(self::$prefix.'online');
    }

    function getUsers($users)
    {
        $keys = array();
        $ret = array();

        foreach($users as $v)
        {
            $keys[] = self::$prefix.'client_'.$v;
        }

        $info = $this->redis->mget($keys);
        foreach($info as $v)
        {
            $ret[] = unserialize($v);
        }
        return $ret;
    }

    function getUser($userid)
    {
        $ret = $this->redis->get($userid);
        $info = unserialize($ret);
        return $info;
    }
}