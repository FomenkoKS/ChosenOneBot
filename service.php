<?php

class Service
{
    private $telegram;
    private $redis;
    private $chat_id;

    public function __construct($telegram)
    {
        $this->redis = new Redis();
        $this->redis->connect('127.0.0.1', 6379);

        $this->telegram = $telegram;
        $this->chat_id=(!is_null($telegram->Callback_Data()))?$telegram->Callback_Query()['from']['id']:$telegram->ChatID();
    }

    public function __deconstruct()
    {
        $this->redis->close();
    }

    public function setTokenMsg()
    {
        $this->redis->hSet('waitToken', $this->chat_id, 1);
        $this->telegram->sendMessage([
            'chat_id' => $this->chat_id,
            'text' => "*Для начала работы создайте своего бота и укажите его токен.*\r\n\r\nЧтобы создать своего бота, перейдите к боту @BotFather и отправьте команду `/newbot`, после чего вам будет предложено ввести имя и юзернейм бота. В ответ BotFather пришлёт сообщение с токеном вашего бота. Вставьте или перешлите токен сюда.",
            'parse_mode' => 'Markdown'
        ]);
        
    }

    public function setChannelMsg()
    {
        $this->redis->hSet('waitChannel', $this->chat_id, 1);
        $this->telegram->sendMessage([
            'chat_id' =>  $this->chat_id,
            'text' => 'Для подключения к каналу добавьте бота в качестве администратора канала, затем пришлите любое сообщение из канала или укажите ссылку, или юзернейм канала. Для отмены выберите команду /cancel.'
        ]);
    }

    public function genMenu()
    {
        $this->cancelWaiting();
        $token = $this->redis->hGet('tokens', $this->chat_id);
        $tg = new Telegram($token);
        
        $bot = $tg->getMe();
        $buttons = [];
        if (isset($bot['result']['username'])) {
            $text = "К системе подключён бот @" . $bot['result']['username'];
            array_push($buttons, [['callback_data' => 'setChannel', 'text' => 'Добавить канал']]);
            
            if($this->redis->sCard('channels:'.$token)>0){
                $text.="\r\n\r\n<b>Подключённые каналы:</b>";
                foreach($this->redis->sMembers('channels:'.$token) as $i){
                    if($this->botIsAdmin($i,$this->chat_id)){
                        $chat=$tg->getChat(['chat_id'=>$i])['result'];
                        $text.="\r\n";
                        $text.=(!isset($chat['username']))?$chat['title']:"<a href='t.me/".$chat['username']."'>".$chat['title']."</a>";
                    }
                }

                if($this->redis->sCard('channels:'.$token)>0){
                    array_push($buttons, [['callback_data' => 'delChannel', 'text' => 'Убрать канал']]);
                }
            }

        } else $text = 'Токен бота не подключён. Чтобы подключить выберите команду /setToken или нажмите кнопку ниже.';
        array_push($buttons, [['callback_data' => 'setToken', 'text' => 'Подключить токен']]);

        $settings = [
            'chat_id' => $this->chat_id,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ])
        ];

        return $settings;
    }

    public function cancelWaiting()
    {
        $this->redis->hSet('waitToken', $this->chat_id, 0);
        $this->redis->hSet('waitChannel', $this->chat_id, 0);
    }

    public function botIsAdmin($chat,$owner)
    {
        $token=$this->redis->hGet('tokens',$owner);
        $tg=new Telegram($token);
        $admins=$tg->getChatAdministrators(['chat_id'=>$chat]);
        $flag=0;
        foreach($admins['result'] as $admin){
            if($admin['user']['id']==explode(':',$token)[0] || $admin['user']['id']==$owner) 
                if($admin['can_post_messages']==1 || $admin['status']=='creator') $flag+=1;
        }
        return ($flag>1)?true:false;
    }

    public function debug($array){
        $this->telegram->sendMessage([
            'chat_id' =>32512143,
            'text' => print_r($array,True)
        ]);
    }

    public function getChatId($chat){
        $token=$this->redis->hGet('tokens',$this->chat_id);
        $tg=new Telegram($token);
        return $tg->getChat(['chat_id'=>$chat])['result']['id'];
    }
}

?>