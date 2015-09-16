<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

/**
 * 聊天主逻辑
 * 主要是处理 onMessage onClose
 */
use \GatewayWorker\Lib\Gateway;
use \GatewayWorker\Lib\Store;

class Event
{

    // 临时数据,在开始或结束时,应想法清理下
    static $agrent_key = "Wiz_ROOM_AGRENT_LIST";
    static $client_key = "Wiz_ROOM_CLIENT_LIST";

    /**
     * @param $client_id
     * @param $message
     * @return bool|void
     */
    public static function onMessage( $client_id, $message )
    {
        // debug
        echo "client:{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']} gateway:{$_SERVER['GATEWAY_ADDR']}:{$_SERVER['GATEWAY_PORT']}  client_id:$client_id session:" . json_encode( $_SESSION ) . " onMessage:" . $message . "\n";

        // 客户端传递的是json数据
        $message_data = json_decode( $message, TRUE );
        if ( !$message_data ) {
            return;
        }

        // 根据类型执行不同的业务
        switch ( $message_data[ 'cmd' ] ) {
            // 客户端回应服务端的心跳
            case 'pong':
                return;
            // 客户端登录 message格式: {cmd:login, name:xx, room_id:1} ，添加到客户端，广播给所有客户端xx进入聊天室
            case 'login':
                $open_id = $message_data[ 'open_id' ];
                // 把昵称,open_id放到session中
                $name = htmlspecialchars( $message_data[ 'name' ] );
                $_SESSION[ 'open_id' ] = $message_data[ 'open_id' ];
                $_SESSION[ 'name' ] = $message_data[ 'name' ];
                $_SESSION[ 'type' ] = 'client';
                // 记录在线用户
                self::setClient( $client_id, 'client', $message_data );

                // 转播给当前房间的所有客户端，xx进入聊天室 message {type:login, client_id:xx, name:xx} 
                $new_message = array( 'cmd' => $message_data[ 'cmd' ], 'fd' => $client_id, 'open_id' => $open_id, 'name' => $name, 'time' => date( 'Y-m-d H:i:s' ) );

                // 获取全部在线客服,进行广播
                $all_agrents = self::getAllClients( 'server' );

                if ( empty( $all_agrents ) ) {
                    // 当没有客服人员在线
                    $message = array( 'cmd' => 'message', 'data' => '客服在线时间 9:00 ~ 20:00' );
                    Gateway::sendToCurrentClient( json_encode( $message ) );
                } else {
                    // 多客服,需要有个分配用户的逻辑
                    Gateway::sendToAll( json_encode( $new_message ), array_keys( $all_agrents) );//通知客服用户上线
                }

                //这里可以加些欢迎提示语
                return;

            // 客户端发言 message: {cmd:say, to_client_id:xx, content:xx}
            case 'message':
                $open_id = $_SESSION[ 'open_id' ];
                $message = array(
                    'cmd'          => 'message',
                    'fd'           => $client_id,
                    'type'         => 'client',
                    'open_id'      => $open_id,
                    'to_client_id' => 'all',
                    'data'         => nl2br( htmlspecialchars( $message_data[ 'data' ] ) ),
                    'time'         => date( 'Y-m-d H:i:s' ),
                );
                // 获取全部在线客服,进行广播,针对全部客服?
                $all_agrents = self::getAllClients( 'server' );
                return Gateway::sendToAll( json_encode( $message ), array_keys($all_agrents) );
            // 服务端登录 message格式: {type:login, name:xx, room_id:1} ，添加到客户端，广播给所有客户端xx进入聊天室
            case 'login_s':
                // (未实现)通过session检测是否为真实客服,这个数据由页面那里放入redis中???

                $session_id = $message_data[ 'cid' ];
                // 把昵称,open_id放到session中
                $name = htmlspecialchars( $message_data[ 'name' ] );

                $_SESSION[ 'cid' ] = $message_data[ 'cid' ];
                $_SESSION[ 'name' ] = $name;
                $_SESSION[ 'type' ] = 'server';

                // 转播给全部在线客服端，xx进入聊天室 message {type:login, client_id:xx, name:xx}
                $new_message = array( 'cmd' => $message_data[ 'cmd' ], 'client_id' => $client_id, 'cid' => $session_id, 'name' => $name, 'time' => date( 'Y-m-d H:i:s' ) );
                // 记录在线客服 , 数据暂时没想好
                self::setClient( $client_id, 'server', array() );
                // 获取全部在线客服,进行广播
                $all_agrents = self::getAllClients( 'server' );

                Gateway::sendToAll( json_encode( $new_message ), array_keys( $all_agrents ) );

                return;
            case 'getOnline':
                // 获取全部在线用户
                $all_clients = self::getAllClients( 'client' );
                $all_clients = array_values( $all_clients );
                // 转播给全部在线客服端，xx进入聊天室 message {type:login, users:xx, name:xx}
                $message = array( 'cmd' => $message_data[ 'cmd' ], 'users' => $all_clients, 'time' => date( 'Y-m-d H:i:s' ) );
                Gateway::sendToCurrentClient( json_encode( $message ) );

                return;
            case 'message_s':
                return Gateway::sendToClient( $message_data[ 'to' ], json_encode( $message_data ) );

//                return Gateway::sendToAll( json_encode( $new_message ), $all_agrents );
        }
    }


    /**
     * 当客户端断开连接时
     * @param integer $client_id 客户端id
     */
    public static function onClose( $client_id )
    {
        // debug
        echo "client:{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']} gateway:{$_SERVER['GATEWAY_ADDR']}:{$_SERVER['GATEWAY_PORT']}  client_id:$client_id onClose:''\n";

        if ( $_SESSION[ 'type' ] == 'client' ) {
            self::delClient( $client_id );
            // 获取全部在线客服,进行广播
            $all_agrents = self::getAllClients( 'server' );
            $message = array( 'cmd' => 'offline', 'fd' => $client_id );
            Gateway::sendToAll( json_encode( $message ), array_keys( $all_agrents) );
        } elseif ( $_SESSION[ 'type' ] == 'agrent' ) {
            self::delClient( $client_id, 'server' );
        }

    }


    // 用户上线记录
    public static function setClient( $client_id, $type = 'client', $data )
    {
        switch ( $type ) {
            case 'client':
                $key = self::$client_key;
                break;
            case 'server':
                $key = self::$agrent_key;
                break;
            default:
                return FALSE;
        }

        $store = Store::instance( 'dialog' );
        $data[ 'fd' ] = $client_id;//追加值
        return $store->HSET( $key, $client_id, $data );
    }

    // 用户下线
    public static function delClient( $client_id, $type = 'client' )
    {
        switch ( $type ) {
            case 'client':
                $key = self::$client_key;
                break;
            case 'server':
                $key = self::$agrent_key;
                break;
            default:
                return FALSE;
        }
        $store = Store::instance( 'dialog' );

        return $store->HDEL( $key, $client_id );
    }

    // 记录全部用户数据到redis
    public static function getAllClients( $type = 'client' )
    {
        switch ( $type ) {
            case 'client':
                $key = self::$client_key;
                break;
            case 'server':
                $key = self::$agrent_key;
                break;
            default:
                return FALSE;
        }

        $store = Store::instance( 'dialog' );
        // 获取全部在线客户端
        $online = Gateway::getOnlineStatus();
        $store_client = $store->HKEYS( $key );
        if(empty($store_client)){
            $store_client = array();
        }
        $diff = array_diff( $store_client, $online );
        $store->MULTI();
        foreach ( $diff as $k => $v ) {
            $store->HDEL( $key, $v );
        }
        $store->EXEC();

        $clients = $store->HGETALL( $key );

        return $clients;
    }


}
