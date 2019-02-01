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
            'text' => 'Пришлите сообщение из канала, укажите ссылку или юзернейм канала. Для отмены выберите команду /cancel.'
        ]);
    }

    public function genMenu()
    {
        $token = $this->redis->hGet('tokens', $this->chat_id());
        $this->telegram->sendMessage([
            'chat_id' =>  $this->chat_id,
            'text' => $token
        ]);
        $tg = new Telegram($token);
        
        $bot = $tg->getMe();
        $buttons = [];
        if (isset($bot['result']['username'])) {
            $text = "К системе подключён бот @" . $bot['result']['username'];
            array_push($buttons, [['callback_data' => 'setChannel', 'text' => 'Добавить канал']]);
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
}

?>