<?php

namespace wcf\system\exporter;

use wbb\data\board\Board;
use wcf\data\user\group\UserGroup;
use wcf\system\database\PostgreSQLDatabase;
use wcf\system\importer\ImportHandler;
use wcf\system\WCF;
use wcf\util\StringUtil;

/**
 * Exporter for Discourse.
 *
 * @author      Marcel Werk
 * @copyright   2001-2023 WoltLab GmbH
 * @license     GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 */
final class DiscourseExporter extends AbstractExporter
{
    /**
     * @inheritDoc
     */
    protected $methods = [
        'com.woltlab.wcf.user.group' => 'UserGroups',
        'com.woltlab.wcf.user' => 'Users',
        'com.woltlab.wcf.user.avatar' => 'UserAvatars',
        'com.woltlab.wbb.board' => 'Boards',
        'com.woltlab.wbb.thread' => 'Threads',
        'com.woltlab.wbb.post' => 'Posts',
        'com.woltlab.wbb.like' => 'Likes',
        'com.woltlab.wbb.attachment' => 'PostAttachments',
        'com.woltlab.wcf.conversation' => 'Conversations',
        'com.woltlab.wcf.conversation.message' => 'ConversationMessages',
        'com.woltlab.wcf.conversation.user' => 'ConversationUsers',
        'com.woltlab.wcf.conversation.attachment' => 'ConversationAttachments',
    ];

    /**
     * @inheritDoc
     */
    public function getSupportedData()
    {
        return [
            'com.woltlab.wcf.user' => [
                'com.woltlab.wcf.user.group',
                'com.woltlab.wcf.user.avatar',
            ],
            'com.woltlab.wcf.conversation' => [
                'com.woltlab.wcf.conversation.attachment',
            ],
            'com.woltlab.wbb.board' => [
                'com.woltlab.wbb.like',
                'com.woltlab.wbb.attachment',
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function init()
    {
        $host = $this->databaseHost;
        $port = 0;
        if (\preg_match('/^(.+?):(\d+)$/', $host, $matches)) {
            // simple check, does not care for valid ip addresses
            $host = $matches[1];
            $port = $matches[2];
        }

        $this->database = new PostgreSQLDatabase(
            $host,
            $this->databaseUser,
            $this->databasePassword,
            $this->databaseName,
            $port
        );
    }

    /**
     * @inheritDoc
     */
    public function validateDatabaseAccess()
    {
        parent::validateDatabaseAccess();

        $sql = "SELECT  COUNT(*)
                FROM    posts";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute();
    }

    /**
     * @inheritDoc
     */
    public function validateFileAccess()
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function getQueue()
    {
        $queue = [];

        // user
        if (\in_array('com.woltlab.wcf.user', $this->selectedData)) {
            if (\in_array('com.woltlab.wcf.user.group', $this->selectedData)) {
                $queue[] = 'com.woltlab.wcf.user.group';
            }

            $queue[] = 'com.woltlab.wcf.user';

            if (\in_array('com.woltlab.wcf.user.avatar', $this->selectedData)) {
                $queue[] = 'com.woltlab.wcf.user.avatar';
            }
        }

        // conversation
        if (\in_array('com.woltlab.wcf.conversation', $this->selectedData)) {
            $queue[] = 'com.woltlab.wcf.conversation';
            $queue[] = 'com.woltlab.wcf.conversation.message';
            $queue[] = 'com.woltlab.wcf.conversation.user';

            if (\in_array('com.woltlab.wcf.conversation.attachment', $this->selectedData)) {
                $queue[] = 'com.woltlab.wcf.conversation.attachment';
            }
        }

        // board
        if (\in_array('com.woltlab.wbb.board', $this->selectedData)) {
            $queue[] = 'com.woltlab.wbb.board';
            $queue[] = 'com.woltlab.wbb.thread';
            $queue[] = 'com.woltlab.wbb.post';

            if (\in_array('com.woltlab.wbb.like', $this->selectedData)) {
                $queue[] = 'com.woltlab.wbb.like';
            }
            if (\in_array('com.woltlab.wbb.attachment', $this->selectedData)) {
                $queue[] = 'com.woltlab.wbb.attachment';
            }
        }

        return $queue;
    }

    private function countRows(string $table): int
    {
        $sql = "SELECT  COUNT(*)
                FROM    {$table}";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute();

        return $statement->fetchSingleColumn();
    }

    public function countUserGroups(): int
    {
        $sql = "SELECT  COUNT(*)
                FROM    groups
                WHERE   user_count > ?";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([0]);

        return $statement->fetchSingleColumn();
    }

    public function exportUserGroups(int $offset, int $limit): void
    {
        $sql = "SELECT      *
                FROM        groups
                WHERE       user_count > ?
                ORDER BY    id";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute([0]);

        while ($row = $statement->fetchArray()) {
            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.user.group')
                ->import(
                    $row['id'],
                    [
                        'groupName' => $row['name'],
                        'groupType' => UserGroup::OTHER,
                    ],
                );
        }
    }

    public function countUsers(): int
    {
        $sql = "SELECT  COUNT(*)
                FROM    users
                WHERE   id > ?";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([0]);

        return $statement->fetchSingleColumn();
    }

    public function exportUsers(int $offset, int $limit): void
    {
        // prepare password update
        $sql = "UPDATE  wcf" . WCF_N . "_user
                SET     password = ?
                WHERE   userID = ?";
        $passwordUpdateStatement = WCF::getDB()->prepareStatement($sql);

        $sql = "SELECT group_id FROM group_users WHERE user_id = ? AND group_id IN (SELECT id FROM groups WHERE user_count > ?)";
        $groupUsersStatement = $this->database->prepareStatement($sql);

        $sql = "SELECT      users.*, user_emails.email
                FROM        users
                LEFT JOIN   user_emails
                            ON (user_emails.user_id = users.id)
                WHERE       users.id > ?";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute([0]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'username' => $row['username'],
                'password' => null,
                'email' => $row['email'],
                'registrationDate' => $row['created_at'] ? \strtotime($row['created_at']) : 0,
                'lastActivityTime' => $row['last_seen_at'] ? \strtotime($row['last_seen_at']) : 0,
            ];

            $groupUsersStatement->execute([$row['id'], 0]);;
            $additionalData = [
                'groupIDs' => $groupUsersStatement->fetchAll(\PDO::FETCH_COLUMN),
            ];

            // import user
            $newUserID = ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.user')
                ->import(
                    $row['id'],
                    $data,
                    $additionalData
                );

            // update password hash
            if ($newUserID && preg_match('/^\$pbkdf2\-([a-z0-9]+)\$i=(\d+),l=(\d+)\$$/i', $row['password_algorithm'], $match)) {
                $hash = \sprintf(
                    "pbkdf2:%s:%s:%s:%d:%d",
                    $row['password_hash'],
                    $row['salt'],
                    $match[1],
                    $match[2],
                    $match[3],
                );
                $passwordUpdateStatement->execute([$hash, $newUserID]);
            }
        }
    }

    public function countBoards(): int
    {
        return $this->countRows('categories');
    }

    public function exportBoards(int $offset, int $limit): void
    {
        $sql = "SELECT      *
                FROM        categories
                ORDER BY    parent_category_id, id";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute([]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'parentID' => $row['parent_category_id'] ?: null,
                'position' => $row['position'],
                'boardType' => Board::TYPE_BOARD,
                'title' => $row['name'],
                'description' => $row['description'] ?: '',
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.board')
                ->import(
                    $row['id'],
                    $data,
                );
        }
    }

    public function countThreads(): int
    {
        $sql = "SELECT  COUNT(*)
                FROM    topics
                WHERE   archetype = ?";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute(['regular']);

        return $statement->fetchSingleColumn();
    }

    public function exportThreads(int $offset, int $limit): void
    {
        $sql = "SELECT      topics.*, users.username
                FROM        topics
                LEFT JOIN   users ON (users.id = topics.user_id)
                WHERE       topics.archetype = ?
                ORDER BY    id";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute(['regular']);
        while ($row = $statement->fetchArray()) {
            $data = [
                'boardID' => $row['category_id'] ?: 1,
                'topic' => $row['title'],
                'time' => \strtotime($row['created_at'] . ' UTC'),
                'userID' => $row['user_id'] > 0 ? $row['user_id'] : null,
                'username' => $row['username'] ?: '',
                'views' => $row['views'],
                'isSticky' => $row['pinned_at'] ? 1 : 0,
                'isClosed' => $row['closed'] ? 1 : 0,
                'isDeleted' => $row['deleted_by_id'] ? 1 : 0,
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.thread')
                ->import(
                    $row['id'],
                    $data,
                );
        }
    }

    public function countPosts(): int
    {
        $sql = "SELECT  MAX(id)
                FROM    posts
                WHERE   topic_id IN (SELECT id FROM topics WHERE archetype = ?)";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute(['regular']);

        return $statement->fetchSingleColumn() ?: 0;
    }

    public function exportPosts(int $offset, int $limit): void
    {
        $sql = "SELECT      posts.*,
                            users.username
                FROM        posts
                LEFT JOIN   users
                ON          users.id = posts.user_id
                WHERE       posts.id BETWEEN ? AND ?
                            AND posts.topic_id IN (SELECT id FROM topics WHERE archetype = ?)
                ORDER BY    posts.id";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit, 'regular']);
        while ($row = $statement->fetchArray()) {
            $data = [
                'threadID' => $row['topic_id'],
                'userID' => $row['user_id'] > 0 ? $row['user_id'] : null,
                'username' => $row['username'] ?: '',
                'subject' => '',
                'message' => $this->fixBBCodes($row['raw']),
                'enableHtml' => 1,
                'time' => \strtotime($row['created_at'] . ' UTC'),
                'isDeleted' => $row['deleted_at'] ? 1 : 0,
                'deleteTime' => $row['deleted_at'] ? \strtotime($row['deleted_at'] . ' UTC') : 0,
                'editorID' => null,
                'editor' => '',
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.post')
                ->import($row['id'], $data);
        }
    }

    public function countLikes(): int
    {
        $sql = "SELECT  MAX(id)
                FROM    post_actions
                WHERE   post_action_type_id IN (SELECT id FROM post_action_types WHERE name_key = ?)";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute(['like']);

        return $statement->fetchSingleColumn() ?: 0;
    }

    public function exportLikes(int $offset, int $limit): void
    {
        $sql = "SELECT      post_actions.*,
                            posts.user_id AS post_user_id
                FROM        post_actions
                LEFT JOIN   posts
                ON          (posts.id = post_actions.post_id)
                WHERE       post_actions.id BETWEEN ? AND ?
                            AND post_actions.post_action_type_id IN (SELECT id FROM post_action_types WHERE name_key = ?)
                ORDER BY    post_actions.id";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit, 'like']);
        while ($row = $statement->fetchArray()) {
            $data = [
                'objectID' => $row['post_id'],
                'objectUserID' => $row['post_user_id'] ?: null,
                'userID' => $row['user_id'],
                'likeValue' => 1,
                'time' => $row['created_at'] ? \strtotime($row['created_at'] . ' UTC') : 0,
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.like')
                ->import(0, $data);
        }
    }

    private function fixBBCodes(string $message): string
    {
        static $parsedown = null;

        if ($parsedown === null) {
            $parsedown = new \Parsedown();
        }

        $out = $parsedown->text($message);

        // fix quote tags
        $out = \preg_replace('/\[quote[^]]+\]/', '[quote]', $out);

        // fix code tags
        $out = \preg_replace(
            '/<pre><code class="language-([a-zA-Z0-9]+)">/',
            '<pre data-file="" data-highlighter="\\1" data-line="1">',
            $out
        );

        // remove embedded uploads
        $out = \preg_replace_callback(
            '/<img src="upload:\/\/([^"]*)"[^>]*>/',
            function ($matches) {
                if (\preg_match('~^(?<hash>[a-zA-Z0-9]+)~', $matches[1], $innerMatches)) {
                    $uploadID = $this->getUploadID($innerMatches['hash']);
                    if (!$uploadID) {
                        return '';
                    }

                    return "[attach]{$uploadID}[/attach]";
                }

                return '';
            },
            $out
        );

        // fix various tags
        $out = \strtr($out, [
            '<blockquote>' => '<woltlab-quote>',
            '</blockquote>' => '</woltlab-quote>',
            '<pre><code>' => '<pre>',
            '</code></pre>' => '</pre>',
            '<code>' => '<kbd>',
            '</code>' => '</kbd>',
        ]);

        // fix paragraphs
        $out = \preg_replace('/<\\/p>\\s*<p>/', '</p><p><br></p><p>', $out);

        return $out;
    }

    public function countUserAvatars(): int
    {
        $sql = "SELECT  COUNT(*)
                FROM    upload_references
                WHERE   target_type = ?";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute(['User']);

        return $statement->fetchSingleColumn();
    }

    public function exportUserAvatars(int $offset, int $limit): void
    {
        $sql = "SELECT      upload_references.*,
                            uploads.url, uploads.original_filename, uploads.extension,
                            uploads.width, uploads.height, uploads.sha1
                FROM        upload_references
                LEFT JOIN   uploads
                ON          (uploads.id = upload_references.upload_id)
                WHERE       upload_references.target_type = ?
                ORDER BY    upload_references.id";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute(['User']);
        while ($row = $statement->fetchArray()) {
            $fileLocation = $this->fileSystemPath . $row['url'];

            $data = [
                'avatarName' => $row['original_filename'],
                'avatarExtension' => $row['extension'],
                'width' => $row['width'],
                'height' => $row['height'],
                'userID' => $row['target_id'],
                'fileHash' => $row['sha1'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.user.avatar')
                ->import(
                    $row['upload_id'],
                    $data,
                    ['fileLocation' => $fileLocation]
                );
        }
    }

    public function countPostAttachments(): int
    {
        return $this->countAttachments('regular');
    }

    public function countConversationAttachments(): int
    {
        return $this->countAttachments('private_message');
    }

    private function countAttachments(string $archetype): int
    {
        $sql = "SELECT  COUNT(*)
                FROM    upload_references
                WHERE   target_type = ?
                        AND target_id IN (SELECT id FROM posts WHERE topic_id IN (SELECT id FROM topics WHERE archetype = ?))";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute(['Post', $archetype]);

        return $statement->fetchSingleColumn();
    }

    public function exportPostAttachments(int $offset, int $limit): void
    {
        $this->exportAttachments('regular', $offset, $limit);
    }

    public function exportConversationAttachments(int $offset, int $limit): void
    {
        $this->exportAttachments('private_message', $offset, $limit);
    }

    private function exportAttachments(string $archetype, int $offset, int $limit): void
    {
        $sql = "SELECT      upload_references.*,
                            uploads.url, uploads.original_filename, uploads.extension,
                            uploads.width, uploads.height, uploads.sha1, uploads.user_id
                FROM        upload_references
                LEFT JOIN   uploads
                ON          (uploads.id = upload_references.upload_id)
                WHERE       upload_references.target_type = ?
                            AND upload_references.target_id IN (SELECT id FROM posts WHERE topic_id IN (SELECT id FROM topics WHERE archetype = ?))
                ORDER BY    upload_references.id";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute(['Post', $archetype]);
        while ($row = $statement->fetchArray()) {
            $fileLocation = $this->fileSystemPath . $row['url'];

            $data = [
                'objectID' => $row['target_id'],
                'userID' => $row['user_id'] ?: null,
                'filename' => $row['original_filename'],
                'downloads' => 0,
                'lastDownloadTime' => 0,
                'uploadTime' => \strtotime($row['created_at'] . ' UTC'),
                'showOrder' => 0,
            ];

            ImportHandler::getInstance()
                ->getImporter($archetype == 'regular' ? 'com.woltlab.wbb.attachment' : 'com.woltlab.wcf.conversation.attachment')
                ->import(
                    $row['upload_id'],
                    $data,
                    ['fileLocation' => $fileLocation]
                );
        }
    }

    public function countConversations(): int
    {
        $sql = "SELECT  COUNT(*)
                FROM    topics
                WHERE   archetype = ?";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute(['private_message']);

        return $statement->fetchSingleColumn();
    }

    public function exportConversations(int $offset, int $limit): void
    {
        $sql = "SELECT      topics.*, users.username
                FROM        topics
                LEFT JOIN   users ON (users.id = topics.user_id)
                WHERE       topics.archetype = ?
                ORDER BY    id";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute(['private_message']);
        while ($row = $statement->fetchArray()) {
            $data = [
                'subject' => $row['title'],
                'time' => \strtotime($row['created_at'] . ' UTC'),
                'userID' => $row['user_id'] > 0 ? $row['user_id'] : null,
                'username' => $row['username'] ?: '',
                'isClosed' => $row['closed'] ? 1 : 0,
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.conversation')
                ->import($row['id'], $data);
        }
    }

    public function countConversationMessages(): int
    {
        $sql = "SELECT  COUNT(*)
                FROM    posts
                WHERE   topic_id IN (SELECT id FROM topics WHERE archetype = ?)";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute(['private_message']);

        return $statement->fetchSingleColumn();
    }

    public function exportConversationMessages(int $offset, int $limit): void
    {
        $sql = "SELECT      posts.*,
                            users.username
                FROM        posts
                LEFT JOIN   users
                ON          users.id = posts.user_id
                WHERE       posts.topic_id IN (SELECT id FROM topics WHERE archetype = ?)
                ORDER BY    posts.id";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute(['private_message']);
        while ($row = $statement->fetchArray()) {
            $data = [
                'conversationID' => $row['topic_id'],
                'userID' => $row['user_id'] > 0 ? $row['user_id'] : null,
                'username' => $row['username'] ?: '',
                'message' => $this->fixBBCodes($row['raw']),
                'time' => \strtotime($row['created_at'] . ' UTC'),
                'enableHtml' => 1,
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.conversation.message')
                ->import($row['id'], $data);
        }
    }

    public function countConversationUsers(): int
    {
        $sql = "SELECT  COUNT(*)
                FROM    topic_users
                WHERE   topic_id IN (SELECT id FROM topics WHERE archetype = ?)
                        AND user_id > ?";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute(['private_message', 0]);

        return $statement->fetchSingleColumn();
    }

    public function exportConversationUsers(int $offset, int $limit): void
    {
        $sql = "SELECT      topic_users.*, users.username
                FROM        topic_users
                LEFT JOIN   users
                ON          (users.id = topic_users.user_id)
                WHERE       topic_users.topic_id IN (SELECT id FROM topics WHERE archetype = ?)
                            AND topic_users.user_id > ?";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute(['private_message', 0]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'conversationID' => $row['topic_id'],
                'participantID' => $row['user_id'],
                'username' => $row['username'],
                'hideConversation' => 0,
                'isInvisible' => 0,
                'lastVisitTime' => $row['last_visited_at'] ? \strtotime($row['last_visited_at'] . ' UTC') : 0,
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.conversation.user')
                ->import(0, $data);
        }
    }

    /**
     * The code below is necessary for the conversion of embedded attachments (uploads).
     * Discourse uses a Base62 encoded version of the Sha1 hash to embed uploads into posts.
     */
    private function getUploadID(string $hash): ?int
    {
        $sql = "SELECT id FROM uploads WHERE sha1 = ?";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([self::convertBase62ToSha1($hash)]);

        return $statement->fetchSingleColumn();
    }

    private static function convertBase62ToSha1(string $base62): string
    {
        $decoded = self::decodeBase62($base62);
        return self::bcdechex($decoded);
    }

    private static function decodeBase62(string $base62): string
    {
        static $keys = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
        static $keysHash = '';
        if (!$keysHash) {
            $keysHash = \array_flip(\str_split($keys));
        }
        static $base = 62;

        $num = '0';
        $len = \strlen($base62) - 1;
        $i = 0;

        while ($i < \strlen($base62)) {
            $pow = bcpow($base, $len - $i);
            $num = bcadd($num, bcmul($keysHash[$base62[$i]], $pow));
            $i++;
        }

        return $num;
    }

    private static function bcdechex(string $dec): string
    {
        $hex = '';
        do {
            $last = bcmod($dec, 16);
            $hex = dechex($last) . $hex;
            $dec = bcdiv(bcsub($dec, $last), 16);
        } while ($dec > 0);
        return $hex;
    }
}
