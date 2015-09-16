var ws = {};
var GET = getRequest();
    
$(document).ready(function () {
    //使用原生WebSocket
    if (window.WebSocket || window.MozWebSocket)
    {
        ws = new WebSocket(webim.server);
    }
    //使用flash websocket
    else if (webim.flash_websocket)
    {
        WEB_SOCKET_SWF_LOCATION = "/static/flash-websocket/WebSocketMain.swf";
        $.getScript("/static/flash-websocket/swfobject.js", function () {
            $.getScript("/static/flash-websocket/web_socket.js", function () {
                ws = new WebSocket(webim.server);
            });
        });
    }
    //使用http xhr长轮循
    else
    {
        ws = new Comet(webim.server);
    }
    listenEvent();
});

function listenEvent() {
    /**
     * 连接建立时触发
     */
    ws.onopen = function (e) {
        //连接成功
        console.log("connect webim server success.");
        //发送登录信息
        msg = new Object();
        msg.cmd = 'login';
        msg.name = wechat_user.name;
        msg.open_id = wechat_user.open_id;
        msg.type = 'client';

        ws.send($.toJSON(msg));
    };

    //有消息到来时触发
    ws.onmessage = function (e) {
        var message = $.evalJSON(e.data);
        var cmd = message.cmd;
        // 客户端仅需要处理收到服务器消息
        if (cmd == 'message' || cmd == 'message_s')
        {
            showNewMsg(message);
        }else if(cmd == 'ping'){
            msg.cmd = 'pong';
            ws.send($.toJSON(msg));
        }

    };

    /**
     * 连接关闭事件
     */
    ws.onclose = function (e) {
        if (confirm("聊天服务器已关闭")) {
            //alert('您已退出聊天室');
            location.href = 'user.html';
        }
    };

    /**
     * 异常事件
     */
    ws.onerror = function (e) {
        alert("异常:" + e.data);
        console.log("onerror");
    };
}

document.onkeydown = function (e) {
    var ev = document.all ? window.event : e;
    if (ev.keyCode == 13) {
        sendMsg($('#inputbox').val(), 'text');
        return false;
    } else {
        return true;
    }
};


/**
 * 显示新消息
 */
function showNewMsg(dataObj) {

    var content;
    content = xssFilter(dataObj.data);
    content = parseXss(content);

    var type = dataObj.type;
    var said = '';
    var time_str;
    var i_said = true;

    if (dataObj.time) {
        time_str = dataObj.time;
    } else {
        time_str = GetDateT();
    }

    $("#msg-template .lim_time").html(time_str);
    $("#msg-template1 .lim_time").html(time_str);

    var html = '';
    if (type == 'client') {
        html += '<span style="color: green"> ' + wechat_user.name + ': </span> ';
    } else {
        i_said = false;
        html += '<span style="color: orange"> 客服:';
        html += '</span> '
    }

    html += content + '</span>';

    $("#msg-template .lim_infotip").html(html);
    $("#msg-template1 .lim_dot").html(html);


    if (i_said) {
        $("#history").append($("#msg-template1").html());        
    }else{
        $("#history").append($("#msg-template").html());        
    }
    
    $('#notewrap')[0].scrollTop = 1000000;
}

function xssFilter(val) {
    val = val.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\x22/g, '&quot;').replace(/\x27/g, '&#39;');
    return val;
}

function parseXss(val) {
    val = val.replace(/#(\d*)/g, '<img src="/static/img/face/$1.gif" />');
    val = val.replace('&amp;', '&');
    return val;
}


function GetDateT(time_stamp) {
    var d;
    d = new Date();

    if (time_stamp) {
        d.setTime(time_stamp * 1000);
    }
    var h, i, s;
    h = d.getHours();
    i = d.getMinutes();
    s = d.getSeconds();

    h = ( h < 10 ) ? '0' + h : h;
    i = ( i < 10 ) ? '0' + i : i;
    s = ( s < 10 ) ? '0' + s : s;
    return h + ":" + i + ":" + s;
}

function getRequest() {
    var url = location.search; // 获取url中"?"符后的字串
    var theRequest = new Object();
    if (url.indexOf("?") != -1) {
        var str = url.substr(1);

        strs = str.split("&");
        for (var i = 0; i < strs.length; i++) {
            var decodeParam = decodeURIComponent(strs[i]);
            var param = decodeParam.split("=");
            theRequest[param[0]] = param[1];
        }

    }
    return theRequest;
}



function sendMsg(content, type) {
    var msg = {};

    //if (typeof content == "string") {
    //    content = content.replace(" ", "&nbsp;");
    //}

    if (!content) {
        return false;
    }

    msg.cmd = 'message';
    msg.data = content;
    msg.type = 'client';//自客户端发出的消息
    ws.send($.toJSON(msg));

    showNewMsg(msg);
    $('#inputbox').val('');
}

$(document).ready(function () {
    var a = '';
    for (var i = 1; i < 20; i++) {
        a = a + '<a class="face" href="#" onclick="selectFace(' + i + ');return false;"><img src="/static/img/face/' + i + '.gif" /></a>';
    }
    $("#show_face").html(a);
});

(function ($) {
    $.fn.extend({
        insertAtCaret: function (myValue) {
            var $t = $(this)[0];
            if (document.selection) {
                this.focus();
                sel = document.selection.createRange();
                sel.text = myValue;
                this.focus();
            }
            else if ($t.selectionStart || $t.selectionStart == '0') {

                var startPos = $t.selectionStart;
                var endPos = $t.selectionEnd;
                var scrollTop = $t.scrollTop;
                $t.value = $t.value.substring(0, startPos) + myValue + $t.value.substring(endPos, $t.value.length);
                this.focus();
                $t.selectionStart = startPos + myValue.length;
                $t.selectionEnd = startPos + myValue.length;
                $t.scrollTop = scrollTop;
            }
            else {

                this.value += myValue;
                this.focus();
            }
        }
    })
})(jQuery);

