<?php
// Токен вашего бота от @BotFather
define('BOT_TOKEN', 'ВАШ_ТОКЕН_БОТА');
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');

// ID администратора (нужно получить у @userinfobot)
define('ADMIN_USERNAME', 'kurul_tg'); // username админа
define('ADMIN_ID', 'ВАШ_TELEGRAM_ID'); // ID админа (замените на реальный)

// Файл для хранения новостей
define('NEWS_FILE', 'news.json');

// Состояния пользователей для добавления новостей
$userStates = [];

// Функция для отправки запросов к Telegram API
function sendRequest($method, $data = []) {
    $url = API_URL . $method;
    $options = [
        'http' => [
            'header' => "Content-Type: application/json\r\n",
            'method' => 'POST',
            'content' => json_encode($data)
        ]
    ];
    $context = stream_context_create($options);
    return file_get_contents($url, false, $context);
}

// Функция для проверки, является ли пользователь администратором
function isAdmin($userId, $username) {
    return ($userId == ADMIN_ID || $username == ADMIN_USERNAME);
}

// Получаем входящее сообщение
$content = file_get_contents('php://input');
$update = json_decode($content, true);

if (!$update) {
    exit;
}

// Загружаем состояния
if (file_exists('states.json')) {
    $userStates = json_decode(file_get_contents('states.json'), true) ?: [];
}

// Обрабатываем сообщения
if (isset($update['message'])) {
    $message = $update['message'];
    $chatId = $message['chat']['id'];
    $userId = $message['from']['id'];
    $username = $message['from']['username'] ?? '';
    $text = $message['text'] ?? '';
    
    // Проверяем, находится ли пользователь в процессе добавления новости
    $userState = $userStates[$userId] ?? null;
    
    if ($userState) {
        handleNewsCreation($userId, $chatId, $message, $userState);
    } else {
        // Обрабатываем команды
        switch ($text) {
            case '/start':
                $keyboard = [
                    'keyboard' => [
                        ['📰 Последние новости'],
                        ['📚 Все новости']
                    ],
                    'resize_keyboard' => true
                ];
                
                sendRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "Добро пожаловать в канал новостей 1000-7team!\n\n"
                             . "Используйте кнопки для навигации по новостям",
                    'reply_markup' => json_encode($keyboard)
                ]);
                break;
                
            case '/help':
                $helpText = "Доступные команды:\n"
                          . "/news - Последние новости\n"
                          . "/allnews - Все новости\n"
                          . "/start - Начало работы\n";
                
                if (isAdmin($userId, $username)) {
                    $helpText .= "/addnews - Добавить новость (только для админа)\n"
                               . "/delnews - Удалить последнюю новость (только для админа)";
                }
                
                sendRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $helpText
                ]);
                break;
                
            case '/news':
            case '📰 Последние новости':
                $news = getLatestNews();
                if ($news) {
                    sendNews($chatId, $news);
                } else {
                    sendRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => "📭 Новостей пока нет"
                    ]);
                }
                break;
                
            case '/allnews':
            case '📚 Все новости':
                $allNews = getAllNews();
                if (!empty($allNews)) {
                    sendRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => "📚 *Все новости:*\nВсего новостей: " . count($allNews),
                        'parse_mode' => 'Markdown'
                    ]);
                    
                    foreach (array_reverse($allNews) as $index => $news) {
                        sendNews($chatId, $news);
                        sleep(1); // Задержка чтобы не спамить
                    }
                } else {
                    sendRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => "📭 Новостей пока нет"
                    ]);
                }
                break;
                
            case '/addnews':
                if (isAdmin($userId, $username)) {
                    // Начинаем процесс добавления новости
                    $userStates[$userId] = ['step' => 'title'];
                    saveStates($userStates);
                    
                    sendRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => "📝 Введите *заголовок* новости:",
                        'parse_mode' => 'Markdown'
                    ]);
                } else {
                    sendRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => "⛔ У вас нет прав для добавления новостей"
                    ]);
                }
                break;
                
            case '/delnews':
                if (isAdmin($userId, $username)) {
                    deleteLastNews($chatId);
                } else {
                    sendRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => "⛔ У вас нет прав для удаления новостей"
                    ]);
                }
                break;
                
            case '/cancel':
                if (isset($userStates[$userId])) {
                    unset($userStates[$userId]);
                    saveStates($userStates);
                    sendRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => "❌ Добавление новости отменено"
                    ]);
                }
                break;
        }
    }
}

// Обработка создания новости
function handleNewsCreation($userId, $chatId, $message, $state) {
    global $userStates;
    
    $text = $message['text'] ?? '';
    
    if ($text == '/cancel') {
        unset($userStates[$userId]);
        saveStates($userStates);
        sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => "❌ Добавление новости отменено"
        ]);
        return;
    }
    
    switch ($state['step']) {
        case 'title':
            if (empty($text)) {
                sendRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "❌ Заголовок не может быть пустым. Введите заголовок:"
                ]);
                return;
            }
            
            $userStates[$userId]['title'] = $text;
            $userStates[$userId]['step'] = 'content';
            saveStates($userStates);
            
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => "✍️ Теперь введите *текст* новости:\n(или отправьте /cancel для отмены)",
                'parse_mode' => 'Markdown'
            ]);
            break;
            
        case 'content':
            if (empty($text)) {
                sendRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "❌ Текст новости не может быть пустым. Введите текст:"
                ]);
                return;
            }
            
            $userStates[$userId]['content'] = $text;
            $userStates[$userId]['step'] = 'image';
            saveStates($userStates);
            
            // Клавиатура для пропуска изображения
            $keyboard = [
                'keyboard' => [
                    ['⏭ Пропустить изображение']
                ],
                'resize_keyboard' => true
            ];
            
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => "🖼 Отправьте *изображение* для новости или нажмите 'Пропустить':",
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard)
            ]);
            break;
            
        case 'image':
            $image = '';
            
            // Проверяем, нажата ли кнопка пропуска
            if ($text == '⏭ Пропустить изображение') {
                // Убираем клавиатуру
                sendRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "✅ Изображение пропущено",
                    'reply_markup' => json_encode(['remove_keyboard' => true])
                ]);
            }
            // Проверяем, отправлено ли фото
            elseif (isset($message['photo'])) {
                $photo = end($message['photo']);
                $fileId = $photo['file_id'];
                $image = $fileId;
                
                sendRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "✅ Изображение получено",
                    'reply_markup' => json_encode(['remove_keyboard' => true])
                ]);
            }
            // Если ничего не отправлено
            else {
                sendRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "❌ Пожалуйста, отправьте изображение или нажмите 'Пропустить'"
                ]);
                return;
            }
            
            // Сохраняем новость
            $news = [
                'title' => $userStates[$userId]['title'],
                'content' => $userStates[$userId]['content'],
                'image' => $image,
                'date' => date('d.m.Y H:i'),
                'author' => '@' . ($message['from']['username'] ?? 'admin')
            ];
            
            $allNews = getAllNews();
            $allNews[] = $news;
            file_put_contents(NEWS_FILE, json_encode($allNews, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            // Очищаем состояние
            unset($userStates[$userId]);
            saveStates($userStates);
            
            // Отправляем подтверждение
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => "✅ *Новость успешно добавлена!*\n\n"
                         . "Заголовок: " . $news['title'] . "\n"
                         . "Дата: " . $news['date'],
                'parse_mode' => 'Markdown'
            ]);
            
            // Показываем превью
            sendNews($chatId, $news);
            break;
    }
}

// Функция для отправки новости
function sendNews($chatId, $news) {
    $text = "📢 *" . $news['title'] . "*\n\n"
          . $news['content'] . "\n\n"
          . "📅 Дата: " . $news['date'] . "\n"
          . "👤 Добавил: " . ($news['author'] ?? 'бот');
    
    if (!empty($news['image'])) {
        sendRequest('sendPhoto', [
            'chat_id' => $chatId,
            'photo' => $news['image'],
            'caption' => $text,
            'parse_mode' => 'Markdown'
        ]);
    } else {
        sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown'
        ]);
    }
}

// Функция для удаления последней новости
function deleteLastNews($chatId) {
    $allNews = getAllNews();
    
    if (empty($allNews)) {
        sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => "📭 Новостей для удаления нет"
        ]);
        return;
    }
    
    $lastNews = array_pop($allNews);
    file_put_contents(NEWS_FILE, json_encode($allNews, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    sendRequest('sendMessage', [
        'chat_id' => $chatId,
        'text' => "✅ Последняя новость удалена:\n\n"
                 . "Заголовок: " . $lastNews['title'] . "\n"
                 . "Дата: " . $lastNews['date']
    ]);
}

// Функции для работы с новостями
function getLatestNews() {
    $news = getAllNews();
    return !empty($news) ? end($news) : null;
}

function getAllNews() {
    if (file_exists(NEWS_FILE)) {
        $content = file_get_contents(NEWS_FILE);
        return json_decode($content, true) ?: [];
    }
    return [];
}

// Функции для сохранения состояний
function saveStates($states) {
    file_put_contents('states.json', json_encode($states));
}

// Для вебхука - отвечаем, что все ок
echo 'OK';
?>
