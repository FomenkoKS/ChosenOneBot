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
        $this->chat_id = (!is_null($telegram->Callback_Data())) ? $telegram->Callback_Query()['from']['id'] : $telegram->ChatID();
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
            'chat_id' => $this->chat_id,
            'text' => 'Для подключения к каналу добавьте бота в качестве администратора канала, затем пришлите любое сообщение из канала или укажите ссылку, или юзернейм канала. Для отмены выберите команду /done.'
        ]);
    }

    public function genPost($chat, $token)
    {
        $buttons = [];
        if ($this->redis->sIsMember('campaigns', $token)) {
            $text = "Каналы участвующие в розыгрыше: " . implode(', ', $this->getChannelList($token)) . ".\r\nЧтобы участвовать в розыгрыше, нажмите кнопку ниже.";
            if (($count = $this->redis->sCard('members:' . $token)) > 0) $text .= "\r\n\r\nКоличество участников: <b>$count</b>";
            array_push($buttons, [[
                'text' => 'Я участвую!',
                'callback_data' => 'accept:' . explode(':', $token)[0]
            ]]);
        } else {
            $text = 'Конкурс завершён.';
        }
        $settings = [
            'chat_id' => $chat,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ])
        ];


        return $settings;
    }

    public function genMenu()
    {
        $this->cancelWaiting();
        $token = $this->redis->hGet('tokens', $this->chat_id);
        $tg = new Telegram($token);

        $bot = $tg->getMe();
        $buttons = [[['callback_data' => 'setToken', 'text' => '🤖 Подключить токен']]];
        if (isset($bot['result']['username'])) {
            $text = "К системе подключён бот @" . $bot['result']['username'];
            array_push($buttons, [['callback_data' => 'setChannel', 'text' => '➕ Добавить канал']]);

            if ($this->redis->sCard('channels:' . $token) > 0) {
                $text .= "\r\n\r\n<b>Подключённые каналы:</b>\r\n";
                $text .= implode("\r\n", $this->getChannelList($token));

                array_push($buttons, [['callback_data' => 'delChannel', 'text' => '➖ Убрать канал']]);
                if ($this->redis->sIsMember('campaigns', $token)) {
                    $text .= "\r\n\r\n<b>Конкурс начат.</b>";
                }

                if (($count = $this->redis->sCard('members:' . $token)) > 0) {
                    $text .= "\r\nКоличество участников: <b>$count</b>.\r\nВы можете выявить победителя, но информация о победителях опубликуется на ваших каналах лишь после нажатия кнопки «Завершить конкурс».";

                    array_push($buttons, [['callback_data' => 'getWinner', 'text' => '🏆 Выявить победителя']]);
                } else {
                    if ($this->redis->sIsMember('campaigns', $token)) {
                        $text .= "\r\n<b>Чтобы обновить информацию о количестве участников, а также выявить победителя необходимо нажать на кнопку «Обновить информацию».</b>";
                        array_push($buttons, [['callback_data' => 'refresh', 'text' => '🔄 Обновить информацию']]);
                    } else {
                        array_push($buttons, [['callback_data' => 'startCampaign', 'text' => '🏁 Начать розыгрыш']]);
                    }
                }

                if (($count = $this->redis->sCard('winners:' . $token)) > 0) {
                    $text .= "\r\n\r\nПобедителей: <b>$count</b>.";
                    array_push($buttons, [['callback_data' => 'endCampaign', 'text' => '⏹ Завершить розыгрыш']]);
                }
            }

        } else $text = "<b>Для начала работы создайте своего бота и укажите его токен.</b>\r\n\r\nЧтобы подключить токен выберите команду /setToken или нажмите кнопку ниже.";
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

    public function botIsAdmin($chat, $owner)
    {
        $token = $this->redis->hGet('tokens', $owner);
        $tg = new Telegram($token);
        $admins = $tg->getChatAdministrators(['chat_id' => $chat]);
        $flag = 0;
        foreach ($admins['result'] as $admin) {
            if ($admin['user']['id'] == explode(':', $token)[0] || $admin['user']['id'] == $owner)
                if ($admin['can_post_messages'] == 1 || $admin['status'] == 'creator') $flag += 1;
        }
        return ($flag > 1) ? true : false;
    }

    public function conditionsComplied(){
        return true;
    }

    public function debug($array)
    {
        $this->telegram->sendMessage([
            'chat_id' => 32512143,
            'text' => print_r($array, true)
        ]);
    }

    public function getChatId($chat)
    {
        $token = $this->redis->hGet('tokens', $this->chat_id);
        $tg = new Telegram($token);
        return $tg->getChat(['chat_id' => $chat])['result']['id'];
    }

    public function getChannelList($token)
    {
        $a = [];
        $tg = new Telegram($token);
        foreach ($this->redis->sMembers('channels:' . $token) as $i) if ($this->botIsAdmin($i, $this->chat_id)) {
            $chat = $tg->getChat(['chat_id' => $i])['result'];
            $title = (!isset($chat['username'])) ? $chat['title'] : "<a href='t.me/" . $chat['username'] . "'>" . $chat['title'] . "</a>";
            array_push($a, $title);
        }
        return $a;
    }
}

?>