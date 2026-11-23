<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
if (PHP_SAPI !== 'cli') exit(1);
if ((int)query('SELECT COUNT(*) FROM threads')->fetchColumn()) { fwrite(STDERR, "Demo seed only runs on an empty forum. No changes made.\n"); exit(1); }
$threads = [
    ['gen', 'The small things that made your day', [
        'A stranger waved me through at the crossing even though I was still half a street away. Also, the kettle is working again. What have you got?',
        'Found a forgotten tenner in my winter coat. Immediately invested it in pastries.',
        'The plant on my desk has a new leaf. We are both doing our best.',
        "Finally fixed the squeaky chair.\n\nIt only took me eight months and about ten seconds.",
        'Someone returned my library book for me when I left it on the bus. Thank you, mysterious book person.',
        'First proper frost this morning. Everything looked like it had been dusted with sugar.',
        'My bread came out looking like actual bread. A personal milestone.',
    ]],
    ['gen', 'Welcome to the board', [
        "This is a small bulletin board for whatever you’re thinking about. No accounts, no introductions required.\n\nPick a board, start a thread, or reply to someone. Images are welcome. Kindness is required.\n\nWhat brought you here?",
        'The distinct lack of notification sounds. Lovely place you have here.',
    ]],
    ['tech', 'Show us your weekend project', [
        "I’m turning an old laptop into a kitchen recipe station. It currently has three recipes and an extremely loud fan.\n\nAnyone else making something unnecessarily complicated?",
        'Built a tiny weather display. It tells me it is raining while I look out of the window at the rain.',
        ">unnecessarily complicated\nI made a spreadsheet to decide which spreadsheet software to use.",
    ]],
    ['art', 'The sketchbook thread', ['A place for works in progress, happy accidents, and drawings on the backs of envelopes. What are you making?', 'Trying to draw the same mug every morning. By Friday it might actually look like a mug.']],
    ['music', 'One album for a rainy afternoon?', ['Nothing urgent to do. Rain on the window. A whole album, start to finish. What are you putting on?', 'Something instrumental, a cup of tea, and absolutely no shuffle.']],
    ['games', 'Games you always come back to', ['The save file is years old. You know every corner of the map. You still start it up anyway. Which game is it for you?', 'Anything with a garden to tend and no time limit. That is my entire review.']],
];
db()->beginTransaction();
foreach ($threads as $index => [$slug, $subject, $messages]) {
    $stamp = gmdate('Y-m-d H:i:s', time() - (count($threads) - $index) * 3600);
    query('INSERT INTO threads (board_slug, subject, created_at, bumped_at, pinned) VALUES (?, ?, ?, ?, ?)', [$slug, $subject, $stamp, $stamp, $index === 1 ? 1 : 0]);
    $id = (int)db()->lastInsertId();
    foreach ($messages as $i => $body) {
        query('INSERT INTO anonymous_users (token_hash) VALUES (?) ON DUPLICATE KEY UPDATE token_hash=anonymous_users.token_hash', ['demo:' . $i]);
        $user = query('SELECT id FROM anonymous_users WHERE token_hash=?', ['demo:' . $i])->fetchColumn();
        query('INSERT INTO posts (thread_id, user_id, body, created_at) VALUES (?, ?, ?, ?)', [$id, $user, $body, gmdate('Y-m-d H:i:s', strtotime($stamp . ' UTC') + $i * 120)]);
    }
}
db()->commit();
echo "Added six friendly demo threads.\n";
