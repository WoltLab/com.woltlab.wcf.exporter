<?php

namespace wcf\system\exporter;

use wbb\data\board\Board;
use wcf\data\user\group\UserGroup;
use wcf\system\database\PostgreSQLDatabase;
use wcf\system\importer\ImportHandler;
use wcf\system\WCF;

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
        'com.woltlab.wbb.attachment' => 'Attachments',
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
        return $this->countRows('topics');
    }

    public function exportThreads(int $offset, int $limit): void
    {
        $sql = "SELECT      topics.*, users.username
                FROM        topics
                LEFT JOIN   users ON (users.id = topics.user_id)
                ORDER BY    id";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute();
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
        return $this->countRows('posts');
    }

    public function exportPosts(int $offset, int $limit): void
    {
        $sql = "SELECT      posts.*,
                            users.username
                FROM        posts
                LEFT JOIN   users
                ON          users.id = posts.user_id
                ORDER BY    posts.id";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $data = [
                'threadID' => $row['topic_id'],
                'userID' => $row['user_id'] > 0 ? $row['user_id'] : null,
                'username' => $row['username'] ?: '',
                'subject' => '',
                'message' => self::fixBBCodes($row['raw']),
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
        $sql = "SELECT  COUNT(*)
                FROM    post_actions
                WHERE   post_action_type_id IN (SELECT id FROM post_action_types WHERE name_key = ?)";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute(['like']);

        return $statement->fetchSingleColumn();
    }

    public function exportLikes(int $offset, int $limit): void
    {
        $sql = "SELECT      post_actions.*,
                            posts.user_id AS post_user_id
                FROM        post_actions
                LEFT JOIN   posts
                ON          (posts.id = post_actions.post_id)
                WHERE       post_actions.post_action_type_id IN (SELECT id FROM post_action_types WHERE name_key = ?)
                ORDER BY    post_actions.id";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute(['like']);
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

    private static function fixBBCodes(string $message): string
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
        $out = \preg_replace(
            '/<img src="upload:\/\/[^"]*"[^>]*>/',
            '',
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

    public function countAttachments(): int
    {
        $sql = "SELECT  COUNT(*)
                FROM    upload_references
                WHERE   target_type = ?";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute(['Post']);

        return $statement->fetchSingleColumn();
    }

    public function exportAttachments(int $offset, int $limit): void
    {
        $sql = "SELECT      upload_references.*,
                            uploads.url, uploads.original_filename, uploads.extension,
                            uploads.width, uploads.height, uploads.sha1, uploads.user_id
                FROM        upload_references
                LEFT JOIN   uploads
                ON          (uploads.id = upload_references.upload_id)
                WHERE       upload_references.target_type = ?
                ORDER BY    upload_references.id";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute(['Post']);
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
                ->getImporter('com.woltlab.wbb.attachment')
                ->import(
                    $row['upload_id'],
                    $data,
                    ['fileLocation' => $fileLocation]
                );
        }
    }
}
