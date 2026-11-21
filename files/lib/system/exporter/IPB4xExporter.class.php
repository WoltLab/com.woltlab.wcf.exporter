<?php

namespace wcf\system\exporter;

use blog\data\blog\Blog;
use wbb\data\board\Board;
use wcf\data\bbcode\media\provider\BBCodeMediaProvider;
use wcf\data\like\Like;
use wcf\data\user\group\UserGroup;
use wcf\system\database\util\PreparedStatementConditionBuilder;
use wcf\system\exception\SystemException;
use wcf\system\importer\ImportHandler;
use wcf\system\WCF;
use wcf\util\JSON;
use wcf\util\StringUtil;
use wcf\util\UserUtil;

/**
 * Exporter for IP.Board 4.x
 *
 * @author  Marcel Werk
 * @copyright   2001-2019 WoltLab GmbH
 * @license GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 */
final class IPB4xExporter extends AbstractExporter
{
    /**
     * language statement
     * @var \wcf\system\database\statement\PreparedStatement
     */
    private $languageStatement;

    /**
     * ipb default language
     * @var integer
     */
    private $defaultLanguageID;

    /**
     * board cache
     * @var array
     */
    protected $boardCache = [];

    /**
     * pages database cache
     * @var array<int, array<string, mixed>>
     */
    private array $wsdbDatabaseCache;

    /**
     * pages field cache (database id => field id => field)
     * @var array<int, array<int, array<string, mixed>>>
     */
    private array $wsdbFieldCache;

    /**
     * @inheritDoc
     */
    protected $methods = [
        'com.woltlab.wcf.user' => 'Users',
        'com.woltlab.wcf.user.group' => 'UserGroups',
        'com.woltlab.wcf.user.follower' => 'Followers',
        'com.woltlab.wcf.user.comment' => 'StatusUpdates',
        'com.woltlab.wcf.user.comment.response' => 'StatusReplies',
        'com.woltlab.wcf.user.avatar' => 'UserAvatars',
        'com.woltlab.wcf.user.option' => 'UserOptions',
        'com.woltlab.wcf.conversation' => 'Conversations',
        'com.woltlab.wcf.conversation.message' => 'ConversationMessages',
        'com.woltlab.wcf.conversation.user' => 'ConversationUsers',
        'com.woltlab.wcf.conversation.attachment' => 'ConversationAttachments',
        'com.woltlab.wbb.board' => 'Boards',
        'com.woltlab.wbb.thread' => 'Threads',
        'com.woltlab.wbb.post' => 'Posts',
        'com.woltlab.wbb.attachment' => 'PostAttachments',
        'com.woltlab.wbb.watchedThread' => 'WatchedThreads',
        'com.woltlab.wbb.poll' => 'Polls',
        'com.woltlab.wbb.poll.option.vote' => 'PollOptionVotes',
        'com.woltlab.wbb.like' => 'Likes',

        'com.woltlab.gallery.category' => 'GalleryCategories',
        'com.woltlab.gallery.album' => 'GalleryAlbums',
        'com.woltlab.gallery.image' => 'GalleryImages',
        'com.woltlab.gallery.image.comment' => 'GalleryComments',
        'com.woltlab.gallery.image.like' => 'GalleryImageLikes',

        'com.woltlab.blog.blog' => 'Blogs',
        'com.woltlab.blog.category' => 'BlogCategories',
        'com.woltlab.blog.entry' => 'BlogEntries',
        'com.woltlab.blog.entry.attachment' => 'BlogAttachments',
        'com.woltlab.blog.entry.comment' => 'BlogComments',
        'com.woltlab.blog.entry.like' => 'BlogEntryLikes',

        'com.woltlab.wsdb.database' => 'WsdbDatabases',
        'com.woltlab.wsdb.category' => 'WsdbCategories',
        'com.woltlab.wsdb.option' => 'WsdbOptions',
        'com.woltlab.wsdb.record' => 'WsdbRecords',
        'com.woltlab.wsdb.record.attachment' => 'WsdbRecordAttachments',
        'com.woltlab.wsdb.record.comment' => 'WsdbRecordComments',
        'com.woltlab.wsdb.record.like' => 'WsdbRecordLikes',
        'com.woltlab.wsdb.review' => 'WsdbReviews',
        'com.woltlab.wsdb.category.watch' => 'WsdbCategoryWatches',
    ];

    /**
     * @inheritDoc
     */
    protected $limits = [
        'com.woltlab.wcf.user' => 200,
        'com.woltlab.wcf.user.avatar' => 100,
        'com.woltlab.wcf.user.follower' => 100,
        'com.woltlab.gallery.image' => 100,
        'com.woltlab.wsdb.option' => 50,
        'com.woltlab.wsdb.record' => 100,
        'com.woltlab.wsdb.record.attachment' => 100,
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
                'com.woltlab.wcf.user.option',
                'com.woltlab.wcf.user.comment',
                'com.woltlab.wcf.user.follower',
            ],
            'com.woltlab.wbb.board' => [
                'com.woltlab.wbb.attachment',
                'com.woltlab.wbb.poll',
                'com.woltlab.wbb.watchedThread',
                'com.woltlab.wbb.like',
            ],
            'com.woltlab.wcf.conversation' => [
                'com.woltlab.wcf.conversation.attachment',
            ],
            'com.woltlab.gallery.image' => [
                'com.woltlab.gallery.category',
                'com.woltlab.gallery.album',
                'com.woltlab.gallery.image.comment',
                'com.woltlab.gallery.image.like',
            ],
            'com.woltlab.blog.entry' => [
                'com.woltlab.blog.category',
                'com.woltlab.blog.entry.attachment',
                'com.woltlab.blog.entry.comment',
                'com.woltlab.blog.entry.like',
            ],
            'com.woltlab.wsdb.record' => [
                'com.woltlab.wsdb.category',
                'com.woltlab.wsdb.record.attachment',
                'com.woltlab.wsdb.record.comment',
                'com.woltlab.wsdb.record.like',
                'com.woltlab.wsdb.review',
                'com.woltlab.wsdb.category.watch',
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function validateDatabaseAccess()
    {
        parent::validateDatabaseAccess();

        $sql = "SELECT  COUNT(*)
                FROM    " . $this->databasePrefix . "core_admin_permission_rows";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute();
    }

    /**
     * @inheritDoc
     */
    public function validateFileAccess()
    {
        if (
            \in_array('com.woltlab.wcf.user.avatar', $this->selectedData)
            || \in_array('com.woltlab.wbb.attachment', $this->selectedData)
            || \in_array('com.woltlab.wcf.conversation.attachment', $this->selectedData)
            || \in_array('com.woltlab.gallery.image', $this->selectedData)
            || \in_array('com.woltlab.blog.entry.attachment', $this->selectedData)
            || \in_array('com.woltlab.wsdb.record.attachment', $this->selectedData)
        ) {
            if (empty($this->fileSystemPath) || !@\file_exists($this->fileSystemPath . 'conf_global.php')) {
                return false;
            }
        }

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
            if (\in_array('com.woltlab.wcf.user.option', $this->selectedData)) {
                $queue[] = 'com.woltlab.wcf.user.option';
            }
            $queue[] = 'com.woltlab.wcf.user';
            if (\in_array('com.woltlab.wcf.user.avatar', $this->selectedData)) {
                $queue[] = 'com.woltlab.wcf.user.avatar';
            }

            if (\in_array('com.woltlab.wcf.user.comment', $this->selectedData)) {
                $queue[] = 'com.woltlab.wcf.user.comment';
                $queue[] = 'com.woltlab.wcf.user.comment.response';
            }

            if (\in_array('com.woltlab.wcf.user.follower', $this->selectedData)) {
                $queue[] = 'com.woltlab.wcf.user.follower';
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
        }

        // board
        if (\in_array('com.woltlab.wbb.board', $this->selectedData)) {
            $queue[] = 'com.woltlab.wbb.board';
            $queue[] = 'com.woltlab.wbb.thread';
            $queue[] = 'com.woltlab.wbb.post';

            if (\in_array('com.woltlab.wbb.attachment', $this->selectedData)) {
                $queue[] = 'com.woltlab.wbb.attachment';
            }
            if (\in_array('com.woltlab.wbb.watchedThread', $this->selectedData)) {
                $queue[] = 'com.woltlab.wbb.watchedThread';
            }
            if (\in_array('com.woltlab.wbb.poll', $this->selectedData)) {
                $queue[] = 'com.woltlab.wbb.poll';
                $queue[] = 'com.woltlab.wbb.poll.option.vote';
            }
            if (\in_array('com.woltlab.wbb.like', $this->selectedData)) {
                $queue[] = 'com.woltlab.wbb.like';
            }
        }

        if (\in_array('com.woltlab.gallery.image', $this->selectedData)) {
            if (\in_array('com.woltlab.gallery.category', $this->selectedData)) {
                $queue[] = 'com.woltlab.gallery.category';
            }
            if (\in_array('com.woltlab.gallery.album', $this->selectedData)) {
                $queue[] = 'com.woltlab.gallery.album';
            }
            $queue[] = 'com.woltlab.gallery.image';
            if (\in_array('com.woltlab.gallery.image.comment', $this->selectedData)) {
                $queue[] = 'com.woltlab.gallery.image.comment';
            }
            if (\in_array('com.woltlab.gallery.image.like', $this->selectedData)) {
                $queue[] = 'com.woltlab.gallery.image.like';
            }
        }

        if (\in_array('com.woltlab.blog.entry', $this->selectedData)) {
            $queue[] = 'com.woltlab.blog.blog';
            if (\in_array('com.woltlab.blog.category', $this->selectedData)) {
                $queue[] = 'com.woltlab.blog.category';
            }
            $queue[] = 'com.woltlab.blog.entry';
            if (\in_array('com.woltlab.blog.entry.attachment', $this->selectedData)) {
                $queue[] = 'com.woltlab.blog.entry.attachment';
            }
            if (\in_array('com.woltlab.blog.entry.comment', $this->selectedData)) {
                $queue[] = 'com.woltlab.blog.entry.comment';
            }
            if (\in_array('com.woltlab.blog.entry.like', $this->selectedData)) {
                $queue[] = 'com.woltlab.blog.entry.like';
            }
        }

        // database (pages)
        if (\in_array('com.woltlab.wsdb.record', $this->selectedData)) {
            $queue[] = 'com.woltlab.wsdb.database';
            if (\in_array('com.woltlab.wsdb.category', $this->selectedData)) {
                $queue[] = 'com.woltlab.wsdb.category';
            }
            $queue[] = 'com.woltlab.wsdb.option';
            $queue[] = 'com.woltlab.wsdb.record';
            if (\in_array('com.woltlab.wsdb.record.attachment', $this->selectedData)) {
                $queue[] = 'com.woltlab.wsdb.record.attachment';
            }
            if (\in_array('com.woltlab.wsdb.record.comment', $this->selectedData)) {
                $queue[] = 'com.woltlab.wsdb.record.comment';
            }
            if (\in_array('com.woltlab.wsdb.review', $this->selectedData)) {
                $queue[] = 'com.woltlab.wsdb.review';
            }
            if (\in_array('com.woltlab.wsdb.record.like', $this->selectedData)) {
                $queue[] = 'com.woltlab.wsdb.record.like';
            }
            if (
                \in_array('com.woltlab.wsdb.category', $this->selectedData)
                && \in_array('com.woltlab.wsdb.category.watch', $this->selectedData)
            ) {
                $queue[] = 'com.woltlab.wsdb.category.watch';
            }
        }

        return $queue;
    }

    /**
     * Counts users.
     */
    public function countUsers()
    {
        return $this->__getMaxID($this->databasePrefix . "core_members", 'member_id');
    }

    /**
     * Exports users.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportUsers($offset, $limit)
    {
        // cache profile fields
        $profileFields = [];
        $sql = "SELECT  *
                FROM    " . $this->databasePrefix . "core_pfields_data";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $profileFields[] = $row;
        }

        // prepare password update
        $sql = "UPDATE  wcf1_user
                SET     password = ?
                WHERE   userID = ?";
        $passwordUpdateStatement = WCF::getDB()->prepare($sql);

        // get users
        $sql = "SELECT      pfields_content.*, members.*
                FROM        " . $this->databasePrefix . "core_members members
                LEFT JOIN   " . $this->databasePrefix . "core_pfields_content pfields_content
                ON          pfields_content.member_id = members.member_id
                WHERE       members.member_id BETWEEN ? AND ?
                ORDER BY    members.member_id";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'username' => $row['name'],
                'password' => null,
                'email' => $row['email'],
                'registrationDate' => $row['joined'],
                'banned' => $row['temp_ban'] == -1 ? 1 : 0,
                'registrationIpAddress' => UserUtil::convertIPv4To6($row['ip_address']),
                'signature' => self::fixMessage($row['signature']),
                'userTitle' => $row['member_title'] ?: '',
                'lastActivityTime' => $row['last_activity'],
            ];

            // get group ids
            $groupIDs = \preg_split('/,/', $row['mgroup_others'], -1, \PREG_SPLIT_NO_EMPTY);
            $groupIDs[] = $row['member_group_id'];

            // get user options
            $options = [];

            // get birthday
            if ($row['bday_day'] && $row['bday_month'] && $row['bday_year']) {
                $options['birthday'] = \sprintf(
                    '%04d-%02d-%02d',
                    $row['bday_year'],
                    $row['bday_month'],
                    $row['bday_day']
                );
            }

            $additionalData = [
                'groupIDs' => $groupIDs,
                'options' => $options,
            ];

            // handle user options
            foreach ($profileFields as $profileField) {
                if (!empty($row['field_' . $profileField['pf_id']])) {
                    $additionalData['options'][$profileField['pf_id']] = $row['field_' . $profileField['pf_id']];
                }
            }

            // import user
            $newUserID = ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.user')
                ->import(
                    $row['member_id'],
                    $data,
                    $additionalData
                );

            // update password hash
            if ($newUserID && $row['members_pass_hash']) {
                if (\str_starts_with($row['members_pass_hash'], '$2')) {
                    $password = 'Bcrypt:' . $row['members_pass_hash'];
                } else {
                    $password = 'cryptMD5:' . $row['members_pass_hash'] . ':' . ($row['members_pass_salt'] ?: '');
                }

                $passwordUpdateStatement->execute([
                    $password,
                    $newUserID,
                ]);
            }
        }
    }

    /**
     * Counts user options.
     */
    public function countUserOptions()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "core_pfields_data";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute();
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Exports user options.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportUserOptions($offset, $limit)
    {
        $sql = "SELECT      *
                FROM        " . $this->databasePrefix . "core_pfields_data
                ORDER BY    pf_id";
        $statement = $this->database->prepareUnmanaged($sql, $limit, $offset);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $data = [
                'categoryName' => 'profile.personal',
                'optionType' => $row['pf_type'] === 'Editor' ? 'message' : 'textarea',
                'askDuringRegistration' => $row['pf_show_on_reg'],
                'editable' => 3,
                'visible' => 15,
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.user.option')
                ->import(
                    $row['pf_id'],
                    $data,
                    [
                        'name' => $this->getLanguageVar('core_pfield', $row['pf_id']),
                    ]
                );
        }
    }

    /**
     * Counts user groups.
     */
    public function countUserGroups()
    {
        return $this->__getMaxID($this->databasePrefix . "core_groups", 'g_id');
    }

    /**
     * Exports user groups.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportUserGroups($offset, $limit)
    {
        $sql = "SELECT      *
                FROM        " . $this->databasePrefix . "core_groups
                WHERE       g_id BETWEEN ? AND ?
                ORDER BY    g_id";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $groupType = UserGroup::OTHER;
            switch ($row['g_id']) {
                case 2: // guests
                    $groupType = UserGroup::GUESTS;
                    break;
                case 3: // users
                    $groupType = UserGroup::USERS;
                    break;
            }

            $data = [
                'groupName' => $this->getLanguageVar('core_group', $row['g_id']),
                'groupType' => $groupType,
                'userOnlineMarking' => !empty($row['prefix']) ? ($row['prefix'] . '%s' . $row['suffix']) : '%s',
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.user.group')
                ->import($row['g_id'], $data);
        }
    }

    /**
     * Counts user avatars.
     */
    public function countUserAvatars()
    {
        $sql = "SELECT  MAX(member_id) AS maxID
                FROM    " . $this->databasePrefix . "core_members
                WHERE   pp_main_photo <> ''";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute();
        $row = $statement->fetchArray();
        if ($row !== false) {
            return $row['maxID'];
        }

        return 0;
    }

    /**
     * Exports user avatars.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportUserAvatars($offset, $limit)
    {
        $sql = "SELECT      *
                FROM        " . $this->databasePrefix . "core_members
                WHERE       member_id BETWEEN ? AND ?
                        AND pp_main_photo <> ''
                ORDER BY    member_id";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $avatarName = \basename($row['pp_main_photo']);
            $source = $this->fileSystemPath . 'uploads/' . $row['pp_main_photo'];

            $data = [
                'avatarName' => $avatarName,
                'userID' => $row['member_id'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.user.avatar')
                ->import(
                    $row['member_id'],
                    $data,
                    ['fileLocation' => $source]
                );
        }
    }

    /**
     * Counts status updates.
     */
    public function countStatusUpdates()
    {
        return $this->__getMaxID($this->databasePrefix . "core_member_status_updates", 'status_id');
    }

    /**
     * Exports status updates.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportStatusUpdates($offset, $limit)
    {
        $sql = "SELECT      status_updates.*, members.name
                FROM        " . $this->databasePrefix . "core_member_status_updates status_updates
                LEFT JOIN   " . $this->databasePrefix . "core_members members
                ON          members.member_id = status_updates.status_author_id
                WHERE       status_updates.status_id BETWEEN ? AND ?
                ORDER BY    status_updates.status_id";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'objectID' => $row['status_member_id'],
                'userID' => $row['status_author_id'],
                'username' => $row['name'] ?: '',
                'message' => self::fixMessage($row['status_content']),
                'time' => $row['status_date'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.user.comment')
                ->import($row['status_id'], $data);
        }
    }

    /**
     * Counts status replies.
     */
    public function countStatusReplies()
    {
        return $this->__getMaxID($this->databasePrefix . "core_member_status_replies", 'reply_id');
    }

    /**
     * Exports status replies.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportStatusReplies($offset, $limit)
    {
        $sql = "SELECT      member_status_replies.*, members.name
                FROM        " . $this->databasePrefix . "core_member_status_replies member_status_replies
                LEFT JOIN   " . $this->databasePrefix . "core_members members
                ON          members.member_id = member_status_replies.reply_member_id
                WHERE       member_status_replies.reply_id BETWEEN ? AND ?
                ORDER BY    member_status_replies.reply_id";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'commentID' => $row['reply_status_id'],
                'time' => $row['reply_date'],
                'userID' => $row['reply_member_id'],
                'username' => $row['name'] ?: '',
                'message' => self::fixMessage($row['reply_content']),
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.user.comment.response')
                ->import($row['reply_id'], $data);
        }
    }

    /**
     * Counts followers.
     */
    public function countFollowers()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "core_follow
                WHERE   follow_app = ?
                    AND follow_area = ?";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute(['core', 'member']);
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Exports followers.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportFollowers($offset, $limit)
    {
        $sql = "SELECT      *
                FROM        " . $this->databasePrefix . "core_follow
                WHERE       follow_app = ?
                        AND follow_area = ?
                ORDER BY    follow_id";
        $statement = $this->database->prepareUnmanaged($sql, $limit, $offset);
        $statement->execute(['core', 'member']);
        while ($row = $statement->fetchArray()) {
            $data = [
                'userID' => $row['follow_member_id'],
                'followUserID' => $row['follow_rel_id'],
                'time' => $row['follow_added'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.user.follower')
                ->import(0, $data);
        }
    }

    /**
     * Counts conversations.
     */
    public function countConversations()
    {
        return $this->__getMaxID($this->databasePrefix . "core_message_topics", 'mt_id');
    }

    /**
     * Exports conversations.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportConversations($offset, $limit)
    {
        $sql = "SELECT      message_topics.*, members.name
                FROM        " . $this->databasePrefix . "core_message_topics message_topics
                LEFT JOIN   " . $this->databasePrefix . "core_members members
                ON          members.member_id = message_topics.mt_starter_id
                WHERE       message_topics.mt_id BETWEEN ? AND ?
                ORDER BY    message_topics.mt_id";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'subject' => $row['mt_title'],
                'time' => $row['mt_date'],
                'userID' => $row['mt_starter_id'] ?: null,
                'username' => $row['mt_is_system'] ? 'System' : ($row['name'] ?: ''),
                'isDraft' => $row['mt_is_draft'] ?? 0,
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.conversation')
                ->import($row['mt_id'], $data);
        }
    }

    /**
     * Counts conversation messages.
     */
    public function countConversationMessages()
    {
        return $this->__getMaxID($this->databasePrefix . "core_message_posts", 'msg_id');
    }

    /**
     * Exports conversation messages.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportConversationMessages($offset, $limit)
    {
        $sql = "SELECT      message_posts.*, members.name
                FROM        " . $this->databasePrefix . "core_message_posts message_posts
                LEFT JOIN   " . $this->databasePrefix . "core_members members
                ON          members.member_id = message_posts.msg_author_id
                WHERE       message_posts.msg_id BETWEEN ? AND ?
                ORDER BY    message_posts.msg_id";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'conversationID' => $row['msg_topic_id'],
                'userID' => $row['msg_author_id'] ?: null,
                'username' => $row['name'] ?: '',
                'message' => self::fixMessage($row['msg_post']),
                'time' => $row['msg_date'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.conversation.message')
                ->import($row['msg_id'], $data);
        }
    }

    /**
     * Counts conversation recipients.
     */
    public function countConversationUsers()
    {
        return $this->__getMaxID($this->databasePrefix . "core_message_topic_user_map", 'map_id');
    }

    /**
     * Exports conversation recipients.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportConversationUsers($offset, $limit)
    {
        $sql = "SELECT      message_topic_user_map.*, members.name
                FROM        " . $this->databasePrefix . "core_message_topic_user_map message_topic_user_map
                LEFT JOIN   " . $this->databasePrefix . "core_members members
                ON          members.member_id = message_topic_user_map.map_user_id
                WHERE       message_topic_user_map.map_id BETWEEN ? AND ?
                ORDER BY    message_topic_user_map.map_id";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'conversationID' => $row['map_topic_id'],
                'participantID' => $row['map_user_id'],
                'username' => $row['name'] ?: '',
                'hideConversation' => $row['map_left_time'] ? 1 : 0,
                'isInvisible' => 0,
                'lastVisitTime' => $row['map_read_time'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.conversation.user')
                ->import(0, $data);
        }
    }

    /**
     * Counts conversation attachments.
     */
    public function countConversationAttachments()
    {
        return $this->countAttachments('core_Messaging');
    }

    /**
     * Exports conversation attachments.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportConversationAttachments($offset, $limit)
    {
        $this->exportAttachments('core_Messaging', 'com.woltlab.wcf.conversation.attachment', $offset, $limit);
    }

    /**
     * Counts boards.
     */
    public function countBoards()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "forums_forums";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute();
        $row = $statement->fetchArray();

        return $row['count'] ? 1 : 0;
    }

    /**
     * Exports boards.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportBoards($offset, $limit)
    {
        $sql = "SELECT      *
                FROM        " . $this->databasePrefix . "forums_forums
                ORDER BY    parent_id, id";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $this->boardCache[$row['parent_id']][] = $row;
        }

        $this->exportBoardsRecursively();
    }

    /**
     * Exports the boards recursively.
     *
     * @param   integer     $parentID
     */
    protected function exportBoardsRecursively($parentID = -1)
    {
        if (!isset($this->boardCache[$parentID])) {
            return;
        }

        foreach ($this->boardCache[$parentID] as $board) {
            $boardType = Board::TYPE_BOARD;
            if ($board['redirect_on']) {
                $boardType = Board::TYPE_LINK;
            } elseif (!$board['sub_can_post']) {
                $boardType = Board::TYPE_CATEGORY;
            }

            $data = [
                'parentID' => $board['parent_id'] != -1 ? $board['parent_id'] : null,
                'position' => $board['position'],
                'boardType' => $boardType,
                'title' => $this->getLanguageVar('forums_forum', $board['id']),
                'description' => $this->getLanguageVar('forums_forum', $board['id'], 'desc'),
                'descriptionUseHtml' => 1,
                'externalURL' => $board['redirect_url'] ?: '',
                'countUserPosts' => $board['inc_postcount'],
                'clicks' => $board['redirect_hits'],
                'posts' => $board['posts'],
                'threads' => $board['topics'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.board')
                ->import($board['id'], $data);

            $this->exportBoardsRecursively($board['id']);
        }
    }

    /**
     * Counts threads.
     */
    public function countThreads()
    {
        return $this->__getMaxID($this->databasePrefix . "forums_topics", 'tid');
    }

    /**
     * Exports threads.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportThreads($offset, $limit)
    {
        // get thread ids
        $threadIDs = [];
        $sql = "SELECT      tid
                FROM        " . $this->databasePrefix . "forums_topics
                WHERE       tid BETWEEN ? AND ?
                ORDER BY    tid";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $threadIDs[] = $row['tid'];
        }

        if (empty($threadIDs)) {
            return;
        }

        // get tags
        $tags = $this->getTags('forums', 'forums', $threadIDs);

        // get threads
        $conditionBuilder = new PreparedStatementConditionBuilder();
        $conditionBuilder->add('topics.tid IN (?)', [$threadIDs]);

        $sql = "SELECT      topics.*
                FROM        " . $this->databasePrefix . "forums_topics topics
                " . $conditionBuilder;
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute($conditionBuilder->getParameters());
        while ($row = $statement->fetchArray()) {
            $data = [
                'boardID' => $row['forum_id'],
                'topic' => $row['title'],
                'time' => $row['start_date'],
                'userID' => $row['starter_id'],
                'username' => $row['starter_name'],
                'views' => $row['views'],
                'isSticky' => $row['pinned'],
                'isDisabled' => $row['approved'] != 1 ? 1 : 0,
                'isClosed' => $row['state'] == 'close' ? 1 : 0,
                'movedThreadID' => $row['moved_to'] ? \intval($row['moved_to']) : null,
                'movedTime' => $row['moved_on'],
                'lastPostTime' => ($row['last_post'] ?: 0),
            ];
            $additionalData = [];
            if (isset($tags[$row['tid']])) {
                $additionalData['tags'] = $tags[$row['tid']];
            }

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.thread')
                ->import(
                    $row['tid'],
                    $data,
                    $additionalData
                );
        }
    }

    /**
     * Counts posts.
     */
    public function countPosts()
    {
        return \max(
            $this->__getMaxID($this->databasePrefix . "forums_posts", 'pid'),
            $this->__getMaxID($this->databasePrefix . "forums_archive_posts", 'archive_id')
        );
    }

    /**
     * Exports posts.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportPosts($offset, $limit)
    {
        $sql = "SELECT      pid, topic_id, author_id, author_name,
                            post, post_date, queued,
                            edit_time, post_edit_reason,
                            ip_address, pdelete_time
                FROM        " . $this->databasePrefix . "forums_posts
                WHERE       pid BETWEEN ? AND ?
                UNION
                SELECT      archive_id AS pid, archive_topic_id AS topic_id, archive_author_id AS author_id, archive_author_name AS author_name,
                            archive_content AS post, archive_content_date AS post_date, archive_queued AS queued,
                            archive_edit_time AS edit_time, archive_edit_reason AS post_edit_reason,
                            archive_ip_address AS ip_address, 0 AS pdelete_time
                FROM        " . $this->databasePrefix . "forums_archive_posts
                WHERE       archive_id BETWEEN ? AND ?
                ORDER BY    pid";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute([$offset + 1, $offset + $limit, $offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'threadID' => $row['topic_id'],
                'userID' => $row['author_id'],
                'username' => $row['author_name'],
                'message' => self::fixMessage($row['post']),
                'time' => $row['post_date'],
                'isDeleted' => ($row['queued'] == 3) ? 1 : 0,
                'isDisabled' => ($row['queued'] != 0 && $row['queued'] != 3) ? 1 : 0,
                'lastEditTime' => $row['edit_time'] ?: 0,
                'editorID' => null,
                'editReason' => $row['post_edit_reason'],
                'ipAddress' => UserUtil::convertIPv4To6($row['ip_address']),
                'deleteTime' => $row['pdelete_time'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.post')
                ->import($row['pid'], $data);
        }
    }

    /**
     * Counts watched threads.
     */
    public function countWatchedThreads()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "core_follow
                WHERE   follow_app = ?
                    AND follow_area = ?";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute(['forums', 'topic']);
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Exports watched threads.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportWatchedThreads($offset, $limit)
    {
        $sql = "SELECT      *
                FROM        " . $this->databasePrefix . "core_follow
                WHERE       follow_app = ?
                        AND follow_area = ?
                ORDER BY    follow_id";
        $statement = $this->database->prepareUnmanaged($sql, $limit, $offset);
        $statement->execute(['forums', 'topic']);
        while ($row = $statement->fetchArray()) {
            $data = [
                'objectID' => $row['follow_rel_id'],
                'userID' => $row['follow_member_id'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.watchedThread')
                ->import(0, $data);
        }
    }

    /**
     * Counts polls.
     */
    public function countPolls()
    {
        return $this->__getMaxID($this->databasePrefix . "core_polls", 'pid');
    }

    /**
     * Exports polls.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportPolls($offset, $limit)
    {
        $sql = "SELECT      polls.*, topics.topic_firstpost
                FROM        " . $this->databasePrefix . "core_polls polls
                LEFT JOIN   " . $this->databasePrefix . "forums_topics topics
                ON          topics.poll_state = polls.pid
                WHERE       pid BETWEEN ? AND ?
                ORDER BY    pid";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            if (!$row['topic_firstpost']) {
                continue;
            }

            try {
                $data = JSON::decode($row['choices']);
            } catch (SystemException | \TypeError $e) {
                $data = @\unserialize($row['choices']); // ipb3.4 fallback
                if (!$data) {
                    $data = @\unserialize(\str_replace('\"', '"', $row['choices'])); // pre ipb3.4 fallback
                }
            }
            if (!$data || !isset($data[1])) {
                continue;
            }

            // import poll
            $pollData = [
                'objectID' => $row['topic_firstpost'],
                'question' => $data[1]['question'],
                'time' => $row['start_date'],
                'isPublic' => $row['poll_view_voters'],
                'maxVotes' => !empty($data[1]['multi']) ? \count($data[1]['choice']) : 1,
                'votes' => $row['votes'],
            ];

            ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll')->import($row['pid'], $pollData);

            // import poll options
            foreach ($data[1]['choice'] as $key => $choice) {
                $optionData = [
                    'pollID' => $row['pid'],
                    'optionValue' => $choice,
                    'showOrder' => $key,
                    'votes' => $data[1]['votes'][$key],
                ];

                ImportHandler::getInstance()
                    ->getImporter('com.woltlab.wbb.poll.option')
                    ->import(
                        ($row['pid'] . '-' . $key),
                        $optionData
                    );
            }
        }
    }

    /**
     * Counts poll option votes.
     */
    public function countPollOptionVotes()
    {
        return $this->__getMaxID($this->databasePrefix . "core_voters", 'vid');
    }

    /**
     * Exports poll option votes.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportPollOptionVotes($offset, $limit)
    {
        $sql = "SELECT      polls.*, voters.*
                FROM        " . $this->databasePrefix . "core_voters voters
                LEFT JOIN   " . $this->databasePrefix . "core_polls polls
                ON          polls.pid = voters.poll
                WHERE       voters.vid BETWEEN ? AND ?
                ORDER BY    voters.vid";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            try {
                $data = JSON::decode($row['member_choices']);
            } catch (SystemException | \TypeError $e) {
                $data = @\unserialize($row['member_choices']); // ipb3.4 fallback
                if (!$data) {
                    $data = @\unserialize(\str_replace('\"', '"', $row['member_choices'])); // pre ipb3.4 fallback
                }
            }
            if (!$data || !isset($data[1])) {
                continue;
            }

            if (!\is_array($data[1])) {
                $data[1] = [$data[1]];
            }

            foreach ($data[1] as $pollOptionKey) {
                $voteData = [
                    'pollID' => $row['pid'],
                    'optionID' => $row['pid'] . '-' . $pollOptionKey,
                    'userID' => $row['member_id'],
                ];

                ImportHandler::getInstance()
                    ->getImporter('com.woltlab.wbb.poll.option.vote')
                    ->import(0, $voteData);
            }
        }
    }

    /**
     * Counts likes.
     */
    public function countLikes()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "core_reputation_index
                WHERE   app = ?
                    AND type = ?";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute(['forums', 'pid']);
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Exports likes.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportLikes($offset, $limit)
    {
        $sql = "SELECT      core_reputation_index.*, forums_posts.author_id
                FROM        " . $this->databasePrefix . "core_reputation_index core_reputation_index
                LEFT JOIN   " . $this->databasePrefix . "forums_posts forums_posts
                ON          forums_posts.pid = core_reputation_index.type_id
                WHERE       core_reputation_index.app = ?
                        AND core_reputation_index.type = ?
                ORDER BY    core_reputation_index.id";
        $statement = $this->database->prepareUnmanaged($sql, $limit, $offset);
        $statement->execute(['forums', 'pid']);
        while ($row = $statement->fetchArray()) {
            $data = [
                'objectID' => $row['type_id'],
                'objectUserID' => $row['author_id'] ?: null,
                'userID' => $row['member_id'],
                'likeValue' => Like::LIKE,
                'time' => $row['rep_date'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.like')
                ->import(0, $data);
        }
    }

    /**
     * Counts post attachments.
     */
    public function countPostAttachments()
    {
        return $this->countAttachments('forums_Forums');
    }

    /**
     * Exports post attachments.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportPostAttachments($offset, $limit)
    {
        $this->exportAttachments('forums_Forums', 'com.woltlab.wbb.attachment', $offset, $limit);
    }

    /**
     * Returns the number of attachments of the given type.
     *
     * @param   string      $type
     * @return  integer
     */
    private function countAttachments($type)
    {
        $id = (\in_array($type, ['blog_Entries', 'cms_Records']) ? 'id1' : 'id2');

        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "core_attachments_map
                WHERE   location_key = ?
                    AND {$id} IS NOT NULL";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute([$type]);
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Exports attachments.
     *
     * @param   string      $type
     * @param   string      $objectType
     * @param   integer     $offset
     * @param   integer     $limit
     */
    private function exportAttachments($type, $objectType, $offset, $limit)
    {
        $id = (\in_array($type, ['blog_Entries', 'cms_Records']) ? 'id1' : 'id2');

        $sql = "SELECT      core_attachments.*, core_attachments_map.id2, core_attachments_map.id1, core_attachments_map.id3
                FROM        " . $this->databasePrefix . "core_attachments_map core_attachments_map
                LEFT JOIN   " . $this->databasePrefix . "core_attachments core_attachments
                ON          core_attachments.attach_id = core_attachments_map.attachment_id
                WHERE       core_attachments_map.location_key = ?
                        AND core_attachments_map.{$id} IS NOT NULL
                ORDER BY    core_attachments_map.attachment_id";
        $statement = $this->database->prepareUnmanaged($sql, $limit, $offset);
        $statement->execute([$type]);
        while ($row = $statement->fetchArray()) {
            if (!$row['attach_id']) {
                continue; // skip orphaned attachments
            }

            $fileLocation = $this->fileSystemPath . 'uploads/' . $row['attach_location'];

            $objectID = $row[$id];
            if ($type === 'cms_Records') {
                // records are identified by the database id (id3) and the record id (id1)
                $databaseID = $this->getWsdbAttachmentDatabaseID($row['id3']);
                if (!$databaseID) {
                    continue;
                }
                $objectID = $databaseID . '-' . $objectID;
            }

            $data = [
                'objectID' => $objectID,
                'userID' => $row['attach_member_id'] ?: null,
                'filename' => $row['attach_file'],
                'downloads' => $row['attach_hits'],
                'uploadTime' => $row['attach_date'],
            ];

            ImportHandler::getInstance()
                ->getImporter($objectType)
                ->import(
                    $row['attach_id'],
                    $data,
                    ['fileLocation' => $fileLocation]
                );
        }
    }

    /**
     * Counts gallery categories.
     */
    public function countGalleryCategories()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "gallery_categories";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute();
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Exports gallery categories.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportGalleryCategories($offset, $limit)
    {
        $sql = "SELECT      *
                FROM        " . $this->databasePrefix . "gallery_categories
                ORDER BY    category_id";
        $statement = $this->database->prepareUnmanaged($sql, $limit, $offset);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $data = [
                'title' => $this->getLanguageVar('gallery_category', $row['category_id']),
                'description' => $this->getLanguageVar('gallery_category', $row['category_id'], 'desc'),
                'parentCategoryID' => $row['category_parent_id'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.gallery.category')
                ->import($row['category_id'], $data);
        }
    }

    /**
     * Counts gallery albums.
     */
    public function countGalleryAlbums()
    {
        return $this->__getMaxID($this->databasePrefix . "gallery_albums", 'album_id');
    }

    /**
     * Exports gallery albums.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportGalleryAlbums($offset, $limit)
    {
        $sql = "SELECT      albums.*, members.name AS username
                FROM        " . $this->databasePrefix . "gallery_albums albums
                LEFT JOIN   " . $this->databasePrefix . "core_members members
                ON          members.member_id = albums.album_owner_id
                WHERE       albums.album_id BETWEEN ? AND ?
                ORDER BY    albums.album_id";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'userID' => $row['album_owner_id'],
                'username' => $row['username'] ?: '',
                'title' => $row['album_name'],
                'description' => $row['album_description'],
                'lastUpdateTime' => $row['album_last_img_date'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.gallery.album')
                ->import($row['album_id'], $data);
        }
    }

    /**
     * Counts gallery images.
     */
    public function countGalleryImages()
    {
        return $this->__getMaxID($this->databasePrefix . "gallery_images", 'image_id');
    }

    /**
     * Exports gallery images.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportGalleryImages($offset, $limit)
    {
        // get ids
        $imageIDs = [];
        $sql = "SELECT      image_id
                FROM        " . $this->databasePrefix . "gallery_images
                WHERE       image_id BETWEEN ? AND ?
                ORDER BY    image_id";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $imageIDs[] = $row['image_id'];
        }
        if (empty($imageIDs)) {
            return;
        }

        $tags = $this->getTags('gallery', 'gallery', $imageIDs);

        // get images
        $conditionBuilder = new PreparedStatementConditionBuilder();
        $conditionBuilder->add('images.image_id IN (?)', [$imageIDs]);

        $sql = "SELECT      images.*, members.name AS username
                FROM        " . $this->databasePrefix . "gallery_images images
                LEFT JOIN   " . $this->databasePrefix . "core_members members
                ON          members.member_id = images.image_member_id
                " . $conditionBuilder;
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute($conditionBuilder->getParameters());
        while ($row = $statement->fetchArray()) {
            $fileLocation = $this->fileSystemPath . 'uploads/' . $row['image_original_file_name'];
            if (!\file_exists($fileLocation)) {
                continue;
            }

            $data = [
                'userID' => $row['image_member_id'] ?: null,
                'username' => $row['username'] ?: '',
                'albumID' => $row['image_album_id'] ?: null,
                'title' => $row['image_caption'],
                'description' => self::fixMessage($row['image_description']),
                'filename' => $row['image_file_name'],
                'fileExtension' => \pathinfo($row['image_file_name'], \PATHINFO_EXTENSION),
                'views' => $row['image_views'],
                'uploadTime' => $row['image_date'],
                'creationTime' => $row['image_date'],
            ];

            $additionalData = [
                'fileLocation' => $fileLocation,
            ];
            $additionalData['categories'] = [$row['image_category_id']];
            if (isset($tags[$row['image_id']])) {
                $additionalData['tags'] = $tags[$row['image_id']];
            }

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.gallery.image')
                ->import(
                    $row['image_id'],
                    $data,
                    $additionalData
                );
        }
    }

    /**
     * Counts gallery comments.
     */
    public function countGalleryComments()
    {
        return $this->__getMaxID($this->databasePrefix . "gallery_comments", 'comment_id');
    }

    /**
     * Exports gallery comments.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportGalleryComments($offset, $limit)
    {
        $sql = "SELECT      *
                FROM        " . $this->databasePrefix . "gallery_comments
                WHERE       comment_id BETWEEN ? AND ?
                ORDER BY    comment_id";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'objectID' => $row['comment_img_id'],
                'userID' => $row['comment_author_id'] ?: null,
                'username' => $row['comment_author_name'],
                'message' => self::fixMessage($row['comment_text']),
                'time' => $row['comment_post_date'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.gallery.image.comment')
                ->import($row['comment_id'], $data);
        }
    }

    /**
     * Counts likes.
     */
    public function countGalleryImageLikes()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "core_reputation_index
                WHERE   app = ?
                    AND type = ?";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute(['gallery', 'image_id']);
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Exports likes.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportGalleryImageLikes($offset, $limit)
    {
        $sql = "SELECT      core_reputation_index.*, gallery_images.image_member_id
                FROM        " . $this->databasePrefix . "core_reputation_index core_reputation_index
                LEFT JOIN   " . $this->databasePrefix . "gallery_images gallery_images
                ON          gallery_images.image_id = core_reputation_index.type_id
                WHERE       core_reputation_index.app = ?
                        AND core_reputation_index.type = ?
                ORDER BY    core_reputation_index.id";
        $statement = $this->database->prepareUnmanaged($sql, $limit, $offset);
        $statement->execute(['gallery', 'image_id']);
        while ($row = $statement->fetchArray()) {
            $data = [
                'objectID' => $row['type_id'],
                'objectUserID' => $row['image_member_id'] ?: null,
                'userID' => $row['member_id'],
                'likeValue' => Like::LIKE,
                'time' => $row['rep_date'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.gallery.image.like')
                ->import(0, $data);
        }
    }

    /**
     * Counts blogs.
     */
    public function countBlogs()
    {
        return $this->__getMaxID($this->databasePrefix . "blog_blogs", 'blog_id');
    }

    /**
     * Exports blogs.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportBlogs($offset, $limit)
    {
        $sql = "SELECT      blogs.*, members.name AS username
                FROM        " . $this->databasePrefix . "blog_blogs blogs
                LEFT JOIN   " . $this->databasePrefix . "core_members members
                ON          members.member_id = blogs.blog_member_id
                WHERE       blogs.blog_id BETWEEN ? AND ?
                ORDER BY    blogs.blog_id";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'userID' => $row['blog_member_id'],
                'username' => $row['username'],
                'title' => $this->getLanguageVar('blogs_blog', $row['blog_id']),
                'description' => self::fixMessage($this->getLanguageVar('blogs_blog', $row['blog_id'], 'desc')),
                'isFeatured' => $row['blog_pinned'],
                'accessLevel' => isset($row['blog_social_group']) ? Blog::ACCESS_OWNER : Blog::ACCESS_EVERYONE,
            ];

            $additionalData = [];
            if (!empty($row['blog_cover_photo'])) {
                $additionalData['coverPhoto'] = $this->fileSystemPath . 'uploads/' . $row['blog_cover_photo'];
            }

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.blog.blog')
                ->import(
                    $row['blog_id'],
                    $data,
                    $additionalData
                );
        }
    }

    /**
     * Counts blog categories.
     */
    public function countBlogCategories()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "blog_categories";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute();
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Exports blog categories.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportBlogCategories($offset, $limit)
    {
        $sql = "SELECT      *
                FROM        " . $this->databasePrefix . "blog_categories
                ORDER BY    category_id";
        $statement = $this->database->prepareUnmanaged($sql, $limit, $offset);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $data = [
                'title' => $row['category_seo_name'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.blog.category')
                ->import($row['category_id'], $data);
        }
    }

    /**
     * Counts blog entries.
     */
    public function countBlogEntries()
    {
        return $this->__getMaxID($this->databasePrefix . "blog_entries", 'entry_id');
    }

    /**
     * Exports blog entries.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportBlogEntries($offset, $limit)
    {
        // get entry ids
        $entryIDs = [];
        $sql = "SELECT      entry_id
                FROM        " . $this->databasePrefix . "blog_entries
                WHERE       entry_id BETWEEN ? AND ?
                ORDER BY    entry_id";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $entryIDs[] = $row['entry_id'];
        }

        if (empty($entryIDs)) {
            return;
        }

        // get tags
        $tags = $this->getTags('blog', 'blogs', $entryIDs);

        // get entries
        $conditionBuilder = new PreparedStatementConditionBuilder();
        $conditionBuilder->add('entry_id IN (?)', [$entryIDs]);

        $sql = "SELECT      blog_entries.*,
                            (SELECT blog_category_id FROM " . $this->databasePrefix . "blog_blogs WHERE blog_id = blog_entries.entry_blog_id) AS category_id
                FROM        " . $this->databasePrefix . "blog_entries blog_entries
                " . $conditionBuilder;
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute($conditionBuilder->getParameters());
        while ($row = $statement->fetchArray()) {
            $additionalData = [];
            if (isset($tags[$row['entry_id']])) {
                $additionalData['tags'] = $tags[$row['entry_id']];
            }
            if ($row['category_id']) {
                $additionalData['categories'] = [$row['category_id']];
            }
            if (!empty($row['entry_cover_photo'])) {
                $additionalData['coverPhoto'] = $this->fileSystemPath . 'uploads/' . $row['entry_cover_photo'];
            }

            $data = [
                'userID' => $row['entry_author_id'],
                'username' => $row['entry_author_name'],
                'subject' => $row['entry_name'],
                'message' => self::fixMessage($row['entry_content']),
                'time' => $row['entry_date'],
                'comments' => $row['entry_num_comments'],
                'views' => $row['entry_views'],
                'blogID' => $row['entry_blog_id'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.blog.entry')
                ->import(
                    $row['entry_id'],
                    $data,
                    $additionalData
                );
        }
    }

    /**
     * Counts blog attachments.
     */
    public function countBlogAttachments()
    {
        return $this->countAttachments('blog_Entries');
    }

    /**
     * Exports blog attachments.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportBlogAttachments($offset, $limit)
    {
        $this->exportAttachments('blog_Entries', 'com.woltlab.blog.entry.attachment', $offset, $limit);
    }

    /**
     * Counts blog comments.
     */
    public function countBlogComments()
    {
        return $this->__getMaxID($this->databasePrefix . "blog_comments", 'comment_id');
    }

    /**
     * Exports blog comments.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportBlogComments($offset, $limit)
    {
        $sql = "SELECT      *
                FROM        " . $this->databasePrefix . "blog_comments
                WHERE       comment_id BETWEEN ? AND ?
                ORDER BY    comment_id";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'objectID' => $row['comment_entry_id'],
                'userID' => $row['comment_member_id'] ?: null,
                'username' => $row['comment_member_name'],
                'message' => self::fixMessage($row['comment_text']),
                'time' => $row['comment_date'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.blog.entry.comment')
                ->import($row['comment_id'], $data);
        }
    }

    /**
     * Counts likes.
     */
    public function countBlogEntryLikes()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "core_reputation_index
                WHERE   app = ?
                    AND type = ?";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute(['blog', 'entry_id']);
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Exports likes.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportBlogEntryLikes($offset, $limit)
    {
        $sql = "SELECT      core_reputation_index.*, blog_entries.entry_author_id
                FROM        " . $this->databasePrefix . "core_reputation_index core_reputation_index
                LEFT JOIN   " . $this->databasePrefix . "blog_entries blog_entries
                ON          blog_entries.entry_id = core_reputation_index.type_id
                WHERE       core_reputation_index.app = ?
                        AND core_reputation_index.type = ?
                ORDER BY    core_reputation_index.id";
        $statement = $this->database->prepareUnmanaged($sql, $limit, $offset);
        $statement->execute(['blog', 'entry_id']);
        while ($row = $statement->fetchArray()) {
            $data = [
                'objectID' => $row['type_id'],
                'objectUserID' => $row['entry_author_id'] ?: null,
                'userID' => $row['member_id'],
                'likeValue' => Like::LIKE,
                'time' => $row['rep_date'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.blog.entry.like')
                ->import(0, $data);
        }
    }

    /**
     * Counts pages databases.
     */
    public function countWsdbDatabases()
    {
        return \count($this->getWsdbDatabases());
    }

    /**
     * Exports pages databases.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportWsdbDatabases($offset, $limit)
    {
        foreach (\array_slice($this->getWsdbDatabases(), $offset, $limit, true) as $databaseID => $row) {
            $options = (int)$row['database_options'];
            $fixedFieldPerms = $row['database_fixed_field_perms'] ? @\json_decode($row['database_fixed_field_perms'], true) : [];
            $displaySettings = $row['database_display_settings'] ? @\json_decode($row['database_display_settings'], true) : [];

            $data = [
                'name' => $this->getLanguageVar('content_db', $databaseID) ?: $row['database_key'],
                'path' => $row['database_key'],
                'enableCategories' => (
                    $row['database_use_categories'] && \in_array('com.woltlab.wsdb.category', $this->selectedData)
                ) ? 1 : 0,
                'enableComments' => ($options & 1) ? 1 : 0,
                'enableReviews' => ($options & 2) ? 1 : 0,
                'enableReactions' => 1,
                'enableLabels' => 0,
                'enableTagging' => $row['database_tags_enabled'] ? 1 : 0,
                'enableCoverPhoto' => !empty($fixedFieldPerms['record_image']['visible']) ? 1 : 0,
                'landingPageType' => (
                    $row['database_use_categories'] && ($displaySettings['index']['type'] ?? '') === 'categories'
                ) ? 'categories' : 'records',
                'listingSortOrder' => \strtoupper($row['database_field_direction'] ?? '') === 'ASC' ? 'ASC' : 'DESC',
                'listingItemsPerPage' => \max(1, \min(100, (int)$row['database_field_perpage'] ?: 20)),
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wsdb.database')
                ->import($databaseID, $data, [
                    'recordSingular' => $this->getLanguageVar('content_db_lang_sl', $databaseID),
                    'recordPlural' => $this->getLanguageVar('content_db_lang_pl', $databaseID),
                ]);
        }
    }

    /**
     * Counts pages categories.
     */
    public function countWsdbCategories()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "cms_database_categories";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute();
        $row = $statement->fetchArray();

        return ($row['count'] ? 1 : 0);
    }

    /**
     * Exports pages categories.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportWsdbCategories($offset, $limit)
    {
        $sql = "SELECT      *
                FROM        " . $this->databasePrefix . "cms_database_categories
                ORDER BY    category_parent_id, category_position, category_id";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute();
        $categories = [];
        while ($row = $statement->fetchArray()) {
            $categories[$row['category_parent_id']][] = $row;
        }

        $this->exportWsdbCategoriesRecursively($categories, 0);
    }

    /**
     * Exports the pages categories with the given parent id and their children.
     *
     * @param   array<int, array<int, array<string, mixed>>>    $categories
     * @param   integer     $parentID
     */
    private function exportWsdbCategoriesRecursively(array $categories, $parentID)
    {
        if (!isset($categories[$parentID])) {
            return;
        }

        foreach ($categories[$parentID] as $row) {
            $data = [
                'title' => $this->getLanguageVar('content_cat_name', $row['category_id']) ?: $row['category_name'],
                'description' => $this->getLanguageVar('content_cat_name', $row['category_id'], 'desc'),
                'parentCategoryID' => $row['category_parent_id'],
                'showOrder' => $row['category_position'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wsdb.category')
                ->import($row['category_id'], $data, [
                    'databaseID' => $row['category_database_id'],
                    'canContainRecords' => $row['category_show_records'] ? 1 : 0,
                ]);

            $this->exportWsdbCategoriesRecursively($categories, $row['category_id']);
        }
    }

    /**
     * Counts pages fields.
     */
    public function countWsdbOptions()
    {
        return \count($this->getWsdbOptionFields());
    }

    /**
     * Exports pages fields.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportWsdbOptions($offset, $limit)
    {
        $fieldCategories = $this->getWsdbFieldCategories();

        foreach (\array_slice($this->getWsdbOptionFields(), $offset, $limit) as $row) {
            $optionType = $this->getWsdbOptionType($row);
            $showInListing = $row['field_display_listing'] ? 1 : 0;

            $configuration = [];
            if ($row['field_required'] && $optionType !== 'boolean') {
                $configuration['required'] = 1;
            }
            if ($row['field_max_length'] > 0 && \in_array($optionType, ['text', 'textarea'])) {
                $configuration['maxLength'] = (int)$row['field_max_length'];
            }

            $data = [
                'databaseID' => $row['field_database_id'],
                'optionType' => $optionType,
                'name' => $this->getLanguageVar('content_field', $row['field_id']) ?: $row['field_key'],
                'description' => $this->getLanguageVar('content_field', $row['field_id'], 'desc'),
                'showOrder' => $row['field_position'],
                'showInListing' => $showInListing,
                'enableFiltering' => $row['field_filter'] ? 1 : 0,
                'listingPosition' => $showInListing ? 'bottom' : null,
                'displayPosition' => $row['field_display_display'] ? 'descriptionList' : null,
                'configuration' => $configuration,
            ];

            $additionalData = [];
            if (\in_array($optionType, ['checkboxes', 'radioButton', 'select'])) {
                $additionalData['selectOptions'] = $this->getWsdbFieldChoices($row);
            }
            if (isset($fieldCategories[$row['field_id']])) {
                $additionalData['categoryIDs'] = $fieldCategories[$row['field_id']];
            }

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wsdb.option')
                ->import($row['field_id'], $data, $additionalData);
        }
    }

    /**
     * Counts pages records.
     */
    public function countWsdbRecords()
    {
        $count = 0;
        foreach (\array_keys($this->getWsdbDatabases()) as $databaseID) {
            $count += $this->countWsdbDatabaseRecords($databaseID);
        }

        return $count;
    }

    /**
     * Exports pages records.
     *
     * Records are stored in a separate table for each database, the offset is
     * therefore mapped onto the tables in the order of the database ids.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportWsdbRecords($offset, $limit)
    {
        foreach (\array_keys($this->getWsdbDatabases()) as $databaseID) {
            if ($limit <= 0) {
                break;
            }

            $count = $this->countWsdbDatabaseRecords($databaseID);
            if ($offset >= $count) {
                $offset -= $count;
                continue;
            }

            $exported = $this->exportWsdbDatabaseRecords($databaseID, $offset, $limit);
            $offset = 0;
            $limit -= $exported;
        }
    }

    /**
     * Exports the records of the given database and returns the number of exported records.
     *
     * @param   integer     $databaseID
     * @param   integer     $offset
     * @param   integer     $limit
     * @return  integer
     */
    private function exportWsdbDatabaseRecords($databaseID, $offset, $limit)
    {
        $database = $this->getWsdbDatabases()[$databaseID];
        $titleField = 'field_' . $database['database_field_title'];
        $contentField = 'field_' . $database['database_field_content'];

        $sql = "SELECT      records.*, core_members.name AS member_name
                FROM        " . $this->databasePrefix . "cms_custom_database_" . $databaseID . " records
                LEFT JOIN   " . $this->databasePrefix . "core_members core_members
                ON          core_members.member_id = records.member_id
                ORDER BY    records.primary_id_field";
        $statement = $this->database->prepareUnmanaged($sql, $limit, $offset);
        $statement->execute();
        $records = [];
        while ($row = $statement->fetchArray()) {
            $records[$row['primary_id_field']] = $row;
        }
        if ($records === []) {
            return 0;
        }

        $tags = [];
        if ($database['database_tags_enabled']) {
            $tags = $this->getTags('cms', 'records' . $databaseID, \array_keys($records));
        }

        $fields = $this->getWsdbFields()[$databaseID] ?? [];
        $memberNames = $this->getWsdbMemberNames($records, $fields);

        foreach ($records as $recordID => $row) {
            $isFuture = $row['record_future_date'] && $row['record_publish_date'] > TIME_NOW;
            $isDeleted = $row['record_approved'] < 0;

            $data = [
                'databaseID' => $databaseID,
                'categoryID' => $row['category_id'] ?: null,
                'userID' => ($row['record_is_anon'] ?? 0) ? null : ($row['member_id'] ?: null),
                'username' => ($row['member_name'] ?? '') ?: ($row['record_author_name'] ?? ''),
                'time' => $row['record_publish_date'] ?: $row['record_saved'],
                'isDisabled' => (!$row['record_approved'] || $isFuture) ? 1 : 0,
                'isDeleted' => $isDeleted ? 1 : 0,
                'deleteTime' => $isDeleted ? TIME_NOW : 0,
                'publicationDate' => $isFuture ? $row['record_publish_date'] : 0,
            ];

            $optionValues = [];
            foreach ($fields as $fieldID => $field) {
                if (!$this->isWsdbOptionField($field)) {
                    continue;
                }

                $value = $this->getWsdbFieldValue($field, $row['field_' . $fieldID] ?? null, $memberNames);
                if ($value !== null) {
                    $optionValues[$fieldID] = $value;
                }
            }

            $additionalData = [
                'title' => (string)($row[$titleField] ?? ''),
                'description' => self::fixMessage($row[$contentField] ?? ''),
                'options' => $optionValues,
                'tags' => $tags[$recordID] ?? [],
            ];

            if ($row['record_image'] && $this->fileSystemPath) {
                $coverPhotoLocation = $this->fileSystemPath . 'uploads/' . $row['record_image'];
                if (\is_readable($coverPhotoLocation)) {
                    $additionalData['coverPhotoLocation'] = $coverPhotoLocation;
                }
            }

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wsdb.record')
                ->import($databaseID . '-' . $recordID, $data, $additionalData);
        }

        return \count($records);
    }

    /**
     * Counts pages record attachments.
     */
    public function countWsdbRecordAttachments()
    {
        return $this->countAttachments('cms_Records');
    }

    /**
     * Exports pages record attachments.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportWsdbRecordAttachments($offset, $limit)
    {
        $this->exportAttachments('cms_Records', 'com.woltlab.wsdb.record.attachment', $offset, $limit);
    }

    /**
     * Counts pages record comments.
     */
    public function countWsdbRecordComments()
    {
        return $this->__getMaxID($this->databasePrefix . "cms_database_comments", 'comment_id');
    }

    /**
     * Exports pages record comments.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportWsdbRecordComments($offset, $limit)
    {
        $sql = "SELECT      *
                FROM        " . $this->databasePrefix . "cms_database_comments
                WHERE       comment_id BETWEEN ? AND ?
                ORDER BY    comment_id";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'objectID' => $row['comment_database_id'] . '-' . $row['comment_record_id'],
                'userID' => ($row['comment_is_anon'] ?? 0) ? null : ($row['comment_user'] ?: null),
                'username' => $row['comment_author'] ?? '',
                'message' => self::fixMessage($row['comment_post']),
                'time' => $row['comment_date'],
                'isDisabled' => $row['comment_approved'] == 1 ? 0 : 1,
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wsdb.record.comment')
                ->import($row['comment_id'], $data);
        }
    }

    /**
     * Counts pages record reviews.
     */
    public function countWsdbReviews()
    {
        return $this->__getMaxID($this->databasePrefix . "cms_database_reviews", 'review_id');
    }

    /**
     * Exports pages record reviews.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportWsdbReviews($offset, $limit)
    {
        $sql = "SELECT      *
                FROM        " . $this->databasePrefix . "cms_database_reviews
                WHERE       review_id BETWEEN ? AND ?
                ORDER BY    review_id";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        $reviews = [];
        while ($row = $statement->fetchArray()) {
            if ($row['review_approved'] != 1 || !isset($this->getWsdbDatabases()[$row['review_database_id']])) {
                continue;
            }

            $reviews[] = $row;
        }

        $recordAuthors = [];
        foreach ($reviews as $row) {
            if ($row['review_author_response']) {
                $recordAuthors[$row['review_database_id']][] = $row['review_item'];
            }
        }
        foreach ($recordAuthors as $databaseID => $recordIDs) {
            $recordAuthors[$databaseID] = $this->getWsdbRecordAuthors($databaseID, $recordIDs);
        }

        foreach ($reviews as $row) {
            $data = [
                'recordID' => $row['review_database_id'] . '-' . $row['review_item'],
                'userID' => $row['review_author'] ?: null,
                'username' => $row['review_author_name'] ?? '',
                'time' => $row['review_date'],
                'message' => self::fixMessage($row['review_content']),
                'rating' => $row['review_rating'],
            ];

            $additionalData = [];
            if ($row['review_author_response']) {
                $author = $recordAuthors[$row['review_database_id']][$row['review_item']] ?? null;
                $additionalData['response'] = [
                    'userID' => $author['userID'] ?? null,
                    'username' => $author['username'] ?? '',
                    'time' => $row['review_edit_time'] ?: $row['review_date'],
                    'message' => self::fixMessage($row['review_author_response']),
                ];
            }

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wsdb.review')
                ->import($row['review_id'], $data, $additionalData);
        }
    }

    /**
     * Counts pages record likes.
     */
    public function countWsdbRecordLikes()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "core_reputation_index
                WHERE   app = ?
                    AND type LIKE ?";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute(['cms', 'record\_id\_%']);
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Exports pages record likes.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportWsdbRecordLikes($offset, $limit)
    {
        $sql = "SELECT      *
                FROM        " . $this->databasePrefix . "core_reputation_index
                WHERE       app = ?
                        AND type LIKE ?
                ORDER BY    id";
        $statement = $this->database->prepareUnmanaged($sql, $limit, $offset);
        $statement->execute(['cms', 'record\_id\_%']);
        $likes = [];
        $recordIDs = [];
        while ($row = $statement->fetchArray()) {
            $databaseID = (int)\substr($row['type'], \strlen('record_id_'));
            if (!isset($this->getWsdbDatabases()[$databaseID])) {
                continue;
            }

            $row['databaseID'] = $databaseID;
            $likes[] = $row;
            $recordIDs[$databaseID][] = $row['type_id'];
        }

        $recordAuthors = [];
        foreach ($recordIDs as $databaseID => $ids) {
            $recordAuthors[$databaseID] = $this->getWsdbRecordAuthors($databaseID, $ids);
        }

        foreach ($likes as $row) {
            $data = [
                'objectID' => $row['databaseID'] . '-' . $row['type_id'],
                'objectUserID' => $recordAuthors[$row['databaseID']][$row['type_id']]['userID'] ?? null,
                'userID' => $row['member_id'],
                'likeValue' => Like::LIKE,
                'time' => $row['rep_date'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wsdb.record.like')
                ->import(0, $data);
        }
    }

    /**
     * Counts watched pages categories.
     */
    public function countWsdbCategoryWatches()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "core_follow
                WHERE   follow_app = ?
                    AND follow_area LIKE ?";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute(['cms', 'categories%']);
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Exports watched pages categories.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportWsdbCategoryWatches($offset, $limit)
    {
        $sql = "SELECT      *
                FROM        " . $this->databasePrefix . "core_follow
                WHERE       follow_app = ?
                        AND follow_area LIKE ?
                ORDER BY    follow_id";
        $statement = $this->database->prepareUnmanaged($sql, $limit, $offset);
        $statement->execute(['cms', 'categories%']);
        while ($row = $statement->fetchArray()) {
            $data = [
                'objectID' => $row['follow_rel_id'],
                'userID' => $row['follow_member_id'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wsdb.category.watch')
                ->import(0, $data);
        }
    }

    /**
     * Returns the id of the pages database an attachment belongs to.
     *
     * @param   ?string     $id3
     * @return  integer
     */
    private function getWsdbAttachmentDatabaseID($id3)
    {
        if ($id3 !== null && \preg_match('/(\d+)/', $id3, $match)) {
            $databaseID = (int)$match[1];
            if (isset($this->getWsdbDatabases()[$databaseID])) {
                return $databaseID;
            }
        }

        // fall back to the only database, if there is just one
        $databaseIDs = \array_keys($this->getWsdbDatabases());
        if (\count($databaseIDs) === 1) {
            return $databaseIDs[0];
        }

        return 0;
    }

    /**
     * Returns the pages databases.
     *
     * @return  array<int, array<string, mixed>>
     */
    private function getWsdbDatabases()
    {
        if (!isset($this->wsdbDatabaseCache)) {
            $this->wsdbDatabaseCache = [];

            $sql = "SELECT      *
                    FROM        " . $this->databasePrefix . "cms_databases
                    ORDER BY    database_id";
            $statement = $this->database->prepareUnmanaged($sql);
            $statement->execute();
            while ($row = $statement->fetchArray()) {
                $this->wsdbDatabaseCache[$row['database_id']] = $row;
            }
        }

        return $this->wsdbDatabaseCache;
    }

    /**
     * Returns the number of records of the given pages database.
     *
     * @param   integer     $databaseID
     * @return  integer
     */
    private function countWsdbDatabaseRecords($databaseID)
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "cms_custom_database_" . $databaseID;
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute();
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Returns the fields of all pages databases.
     *
     * @return  array<int, array<int, array<string, mixed>>>
     */
    private function getWsdbFields()
    {
        if (!isset($this->wsdbFieldCache)) {
            $this->wsdbFieldCache = [];

            $sql = "SELECT      *
                    FROM        " . $this->databasePrefix . "cms_database_fields
                    ORDER BY    field_database_id, field_position, field_id";
            $statement = $this->database->prepareUnmanaged($sql);
            $statement->execute();
            while ($row = $statement->fetchArray()) {
                if (!isset($this->getWsdbDatabases()[$row['field_database_id']])) {
                    continue;
                }

                $this->wsdbFieldCache[$row['field_database_id']][$row['field_id']] = $row;
            }
        }

        return $this->wsdbFieldCache;
    }

    /**
     * Returns the fields that are imported as options.
     *
     * @return  array<int, array<string, mixed>>
     */
    private function getWsdbOptionFields()
    {
        $fields = [];
        foreach ($this->getWsdbFields() as $databaseFields) {
            foreach ($databaseFields as $field) {
                if ($this->isWsdbOptionField($field)) {
                    $fields[] = $field;
                }
            }
        }

        return $fields;
    }

    /**
     * Returns true if the given field is imported as an option. The title and the
     * content field are imported as the title and the description of the record.
     *
     * @param   array<string, mixed>    $field
     * @return  boolean
     */
    private function isWsdbOptionField(array $field)
    {
        $database = $this->getWsdbDatabases()[$field['field_database_id']];
        if (
            $field['field_id'] == $database['database_field_title']
            || $field['field_id'] == $database['database_field_content']
        ) {
            return false;
        }

        return $this->getWsdbOptionType($field) !== '';
    }

    /**
     * Returns the option type for the given field or an empty string if the field
     * type is not supported.
     *
     * @param   array<string, mixed>    $field
     * @return  string
     */
    private function getWsdbOptionType(array $field)
    {
        switch ($field['field_type']) {
            case 'Text':
            case 'Tel':
            case 'Address':
            case 'Member':
                return 'text';

            case 'TextArea':
                return 'textarea';

            case 'Editor':
                return 'wysiwyg';

            case 'Codemirror':
                return 'sourceCode';

            case 'Radio':
                return 'radioButton';

            case 'Select':
                return $field['field_is_multiple'] ? 'checkboxes' : 'select';

            case 'CheckboxSet':
                return 'checkboxes';

            case 'Checkbox':
            case 'YesNo':
                return 'boolean';

            case 'Date':
                return 'date';

            case 'Number':
                return 'float';

            case 'Url':
            case 'Youtube':
            case 'Spotify':
            case 'Soundcloud':
                return 'url';

            case 'Email':
                return 'email';

            default:
                // Item, Upload, Poll, Password and unknown field types
                return '';
        }
    }

    /**
     * Returns the choices of the given selection field.
     *
     * @param   array<string, mixed>    $field
     * @return  array<string, string>
     */
    private function getWsdbFieldChoices(array $field)
    {
        $choices = [];
        $extra = $field['field_extra'] ? @\json_decode($field['field_extra'], true) : null;
        if (\is_array($extra)) {
            foreach ($extra as $key => $value) {
                if (\is_array($value)) {
                    // newer versions store the choices as key/value pairs
                    $key = $value['key'] ?? $key;
                    $value = $value['value'] ?? '';
                }

                $choices[(string)$key] = (string)$value;
            }
        }

        return $choices;
    }

    /**
     * Returns the ids of the categories an option is limited to (field id => category ids).
     * Fields that are available in all categories are omitted.
     *
     * @return  array<int, int[]>
     */
    private function getWsdbFieldCategories()
    {
        $sql = "SELECT  category_id, category_database_id, category_fields
                FROM    " . $this->databasePrefix . "cms_database_categories";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute();
        $categories = [];
        while ($row = $statement->fetchArray()) {
            $categories[] = $row;
        }

        $fieldCategories = [];
        foreach ($this->getWsdbFields() as $databaseID => $fields) {
            $databaseCategories = \array_filter(
                $categories,
                static fn ($category) => $category['category_database_id'] == $databaseID
            );

            foreach (\array_keys($fields) as $fieldID) {
                $categoryIDs = [];
                $isLimited = false;
                foreach ($databaseCategories as $category) {
                    $allowedFields = null;
                    if ($category['category_fields'] && $category['category_fields'] !== '*') {
                        $allowedFields = @\json_decode($category['category_fields'], true);
                    }

                    if (\is_array($allowedFields) && !\in_array($fieldID, $allowedFields)) {
                        $isLimited = true;
                    } else {
                        $categoryIDs[] = $category['category_id'];
                    }
                }

                if ($isLimited && $categoryIDs !== []) {
                    $fieldCategories[$fieldID] = $categoryIDs;
                }
            }
        }

        return $fieldCategories;
    }

    /**
     * Returns the value of a field in the format of the matching option type.
     *
     * @param   array<string, mixed>    $field
     * @param   mixed                   $value
     * @param   array<int, string>      $memberNames
     * @return  mixed
     */
    private function getWsdbFieldValue(array $field, $value, array $memberNames)
    {
        if ($value === null || $value === '') {
            return null;
        }

        switch ($field['field_type']) {
            case 'Member':
                $names = [];
                foreach (\preg_split('/[\s,]+/', $value, -1, \PREG_SPLIT_NO_EMPTY) as $memberID) {
                    if (isset($memberNames[$memberID])) {
                        $names[] = $memberNames[$memberID];
                    }
                }

                return $names !== [] ? \implode(', ', $names) : null;

            case 'Editor':
                return self::fixMessage($value);

            case 'Checkbox':
            case 'YesNo':
                return $value ? 1 : 0;

            case 'Date':
                if (\is_numeric($value)) {
                    return \gmdate('Y-m-d', (int)$value);
                }
                $time = \strtotime($value);

                return $time !== false ? \gmdate('Y-m-d', $time) : null;

            case 'Number':
                return \is_numeric($value) ? (float)$value : null;

            case 'Select':
            case 'CheckboxSet':
                return \implode(',', \array_filter(
                    \array_map('trim', \explode(',', $value)),
                    static fn ($choice) => $choice !== ''
                ));

            case 'Address':
                $address = @\json_decode($value, true);
                if (\is_array($address)) {
                    $parts = [];
                    \array_walk_recursive($address, static function ($part) use (&$parts) {
                        if (\is_scalar($part) && (string)$part !== '') {
                            $parts[] = (string)$part;
                        }
                    });

                    return \implode(', ', $parts);
                }

                return $value;

            default:
                return $value;
        }
    }

    /**
     * Returns the names of the members referenced in member fields of the given records.
     *
     * @param   array<int, array<string, mixed>>    $records
     * @param   array<int, array<string, mixed>>    $fields
     * @return  array<int, string>
     */
    private function getWsdbMemberNames(array $records, array $fields)
    {
        $memberIDs = [];
        foreach ($fields as $fieldID => $field) {
            if ($field['field_type'] !== 'Member') {
                continue;
            }

            foreach ($records as $row) {
                if (!empty($row['field_' . $fieldID])) {
                    foreach (\preg_split('/[\s,]+/', $row['field_' . $fieldID], -1, \PREG_SPLIT_NO_EMPTY) as $memberID) {
                        $memberIDs[] = (int)$memberID;
                    }
                }
            }
        }
        $memberIDs = \array_unique(\array_filter($memberIDs));
        if ($memberIDs === []) {
            return [];
        }

        $conditionBuilder = new PreparedStatementConditionBuilder();
        $conditionBuilder->add('member_id IN (?)', [$memberIDs]);

        $sql = "SELECT  member_id, name
                FROM    " . $this->databasePrefix . "core_members
                " . $conditionBuilder;
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute($conditionBuilder->getParameters());

        return $statement->fetchMap('member_id', 'name');
    }

    /**
     * Returns the authors of the given records (record id => userID, username).
     *
     * @param   integer     $databaseID
     * @param   integer[]   $recordIDs
     * @return  array<int, array{userID: ?int, username: string}>
     */
    private function getWsdbRecordAuthors($databaseID, array $recordIDs)
    {
        $conditionBuilder = new PreparedStatementConditionBuilder();
        $conditionBuilder->add('records.primary_id_field IN (?)', [\array_unique($recordIDs)]);

        $sql = "SELECT      records.primary_id_field, records.member_id, records.record_author_name,
                            core_members.name AS member_name
                FROM        " . $this->databasePrefix . "cms_custom_database_" . $databaseID . " records
                LEFT JOIN   " . $this->databasePrefix . "core_members core_members
                ON          core_members.member_id = records.member_id
                " . $conditionBuilder;
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute($conditionBuilder->getParameters());
        $authors = [];
        while ($row = $statement->fetchArray()) {
            $authors[$row['primary_id_field']] = [
                'userID' => $row['member_id'] ?: null,
                'username' => ($row['member_name'] ?? '') ?: ($row['record_author_name'] ?? ''),
            ];
        }

        return $authors;
    }

    /**
     * Returns the data of tags.
     *
     * @param   string      $app
     * @param   string      $area
     * @param   integer[]   $objectIDs
     * @return  string[][]
     */
    private function getTags($app, $area, array $objectIDs)
    {
        $tags = [];
        $conditionBuilder = new PreparedStatementConditionBuilder();
        $conditionBuilder->add('tag_meta_app = ?', [$app]);
        $conditionBuilder->add('tag_meta_area = ?', [$area]);
        $conditionBuilder->add('tag_meta_id IN (?)', [$objectIDs]);

        // get taggable id
        $sql = "SELECT  tag_meta_id, tag_text
                FROM    " . $this->databasePrefix . "core_tags
                " . $conditionBuilder;
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute($conditionBuilder->getParameters());
        while ($row = $statement->fetchArray()) {
            if (!isset($tags[$row['tag_meta_id']])) {
                $tags[$row['tag_meta_id']] = [];
            }
            $tags[$row['tag_meta_id']][] = $row['tag_text'];
        }

        return $tags;
    }

    /**
     * Returns the id of the default language in the imported board.
     *
     * @return  integer
     */
    private function getDefaultLanguageID()
    {
        if ($this->defaultLanguageID === null) {
            $sql = "SELECT  lang_id
                    FROM    " . $this->databasePrefix . "core_sys_lang
                    WHERE   lang_default = ?";
            $statement = $this->database->prepareUnmanaged($sql);
            $statement->execute([1]);
            $row = $statement->fetchArray();
            if ($row !== false) {
                $this->defaultLanguageID = $row['lang_id'];
            } else {
                $this->defaultLanguageID = 0;
            }
        }

        return $this->defaultLanguageID;
    }

    /**
     * Returns the value of a language variable.
     *
     * @param   string      $prefix
     * @param   integer     $id
     * @param   string      $suffix
     * @return  string
     */
    private function getLanguageVar($prefix, $id, $suffix = '')
    {
        if ($this->languageStatement === null) {
            $sql = "SELECT  word_custom, word_default
                    FROM    " . $this->databasePrefix . "core_sys_lang_words
                    WHERE   lang_id = ?
                        AND word_key = ?";
            $this->languageStatement = $this->database->prepareUnmanaged($sql, 1);
        }
        $this->languageStatement->execute([
            $this->getDefaultLanguageID(),
            ($prefix . '_' . $id . ($suffix ? ('_' . $suffix) : '')),
        ]);
        $row = $this->languageStatement->fetchArray();
        if ($row !== false) {
            return $row['word_custom'] ?: $row['word_default'];
        }

        return '';
    }

    /**
     * Returns message with fixed formatting as used in WCF.
     *
     * @param   string      $string
     * @return  string
     */
    private static function fixMessage($string)
    {
        $string = StringUtil::unifyNewlines($string);

        // remove newlines, but preserve them in code blocks
        $codes = [];
        $string = \preg_replace_callback(
            '~<pre[^>]*>(.*?)</pre>~is',
            static function ($content) use (&$codes) {
                $i = \count($codes);
                $codes[$i] = $content[1];

                return '@@@WCF_CODE_BLOCK_' . $i . '@@@';
            },
            $string
        );
        $string = \str_replace("\n", '', $string);

        // align
        $string = \preg_replace(
            '~<p style="text-align:(left|center|right);">(.*?)</p>~is',
            "[align=\\1]\\2[/align]\n\n",
            $string
        );

        // Remove blank paragraphs, as Invision uses them to create spacing between other paragraphs.
        $string = \str_ireplace('<p></p>', '', $string);

        // <p> to newline
        $string = \str_ireplace('<p>', "", $string);
        $string = \str_ireplace('</p>', "\n\n", $string);
        $string = \str_ireplace('<br>', "\n", $string);

        // strike
        $string = \str_ireplace('<s>', '[s]', $string);
        $string = \str_ireplace('</s>', '[/s]', $string);

        // super
        $string = \str_ireplace('<sup>', '[sup]', $string);
        $string = \str_ireplace('</sup>', '[/sup]', $string);

        // subscript
        $string = \str_ireplace('<sub>', '[sub]', $string);
        $string = \str_ireplace('</sub>', '[/sub]', $string);

        // bold
        $string = \str_ireplace('<strong>', '[b]', $string);
        $string = \str_ireplace('</strong>', '[/b]', $string);
        $string = \str_ireplace('<b>', '[b]', $string);
        $string = \str_ireplace('</b>', '[/b]', $string);

        // italic
        $string = \str_ireplace('<em>', '[i]', $string);
        $string = \str_ireplace('</em>', '[/i]', $string);
        $string = \str_ireplace('<i>', '[i]', $string);
        $string = \str_ireplace('</i>', '[/i]', $string);

        // underline
        $string = \str_ireplace('<u>', '[u]', $string);
        $string = \str_ireplace('</u>', '[/u]', $string);

        // font color
        $string = \preg_replace_callback(
            '~<span style="color:\s*([^";]+);?">(.*?)</span>~is',
            static function ($matches) {
                if (\preg_match('~^rgb\((\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\)$~', $matches[1], $rgbMatches)) {
                    $r = \dechex($rgbMatches[1]);
                    if (\strlen($r) < 2) {
                        $r = '0' . $r;
                    }
                    $g = \dechex($rgbMatches[2]);
                    if (\strlen($g) < 2) {
                        $g = '0' . $g;
                    }
                    $b = \dechex($rgbMatches[3]);
                    if (\strlen($b) < 2) {
                        $b = '0' . $b;
                    }

                    $color = '#' . $r . $g . $b;
                } elseif (\preg_match('~^(?:#(?:[0-9a-f]{3}|[0-9a-f]{6})|[a-z]+)$~', $matches[1])) {
                    $color = $matches[1];
                } else {
                    return $matches[0];
                }

                return '[color=' . $color . ']' . $matches[2] . '[/color]';
            },
            $string
        );

        // font size
        $string = \preg_replace('~<span style="font-size:(\d+)px;">(.*?)</span>~is', '[size=\\1]\\2[/size]', $string);

        // font face
        $string = \preg_replace_callback(
            '~<span style="font-family:(.*?)">(.*?)</span>~is',
            static function ($matches) {
                $font = \str_replace(";", '', \str_replace("'", '', $matches[1]));

                return "[font='" . $font . "']" . $matches[2] . "[/font]";
            },
            $string
        );

        // replace base_url placeholder in urls/images
        $string = \str_ireplace('<___base_url___>/', WCF::getPath(), $string);

        // replace `<fileStore.core_Attachment>` to simplify regex
        $string = \str_ireplace('<fileStore.core_Attachment>', '', $string);

        // embedded attachments
        $string = \preg_replace(
            '~<a class="ipsAttachLink" (?:rel="[^"]*" )?href="[^"]*id=(\d+)[^"]*".*?</a>~i',
            '[attach]\\1[/attach]',
            $string
        );
        $string = \preg_replace(
            '~<a.*?><img data-fileid="(\d+)".*?</a>~i',
            '[attach]\\1[/attach]',
            $string
        );
        $string = \preg_replace(
            '~<img[^>]*data-fileid="(\d+)"[^>]*>~i',
            '[attach]\\1[/attach]',
            $string
        );

        // urls
        $string = \preg_replace(
            '~<a.*?href=(?:"|\')mailto:([^"]*)(?:"|\').*?>(.*?)</a>~is',
            '[email=\'\\1\']\\2[/email]',
            $string
        );
        $string = \preg_replace(
            '~<a.*?href=(?:"|\')([^"\']*)(?:"|\').*?>(.*?)</a>~is',
            '[url=\'\\1\']\\2[/url]',
            $string
        );

        // quotes
        $string = \preg_replace(
            '~<blockquote[^>]*data-author="([^"]+)"[^>]*>(.*?)</blockquote>~is',
            "[quote='\\1']\\2[/quote]",
            $string
        );
        $string = \preg_replace(
            '~<blockquote[^>]*data-ipsquote-username="([^"]+)"[^>]*>(.*?)</blockquote>~is',
            "[quote='\\1']\\2[/quote]",
            $string
        );
        $string = \preg_replace(
            '~<blockquote[^>]*>(.*?)</blockquote>~is',
            '[quote]\\1[/quote]',
            $string
        );
        $string = \preg_replace(
            '~<header class="ipsQuote_citation">(.*?)</header>~is',
            '',
            $string
        );

        // code
        for ($i = 0, $length = \count($codes); $i < $length; $i++) {
            $string = \str_replace('@@@WCF_CODE_BLOCK_' . $i . '@@@', '[code]' . $codes[$i] . '[/code]', $string);
        }

        // smileys
        $string = \preg_replace(
            '~<img title="([^"]*)" alt="[^"]*" src="<fileStore.core_Emoticons>[^"]*">~is',
            '\\1',
            $string
        );
        $string = \preg_replace(
            '~<img src="<fileStore.core_Emoticons>[^"]*" alt="[^"]*" title="([^"]*)">~is',
            '\\1',
            $string
        );

        // list
        $string = \str_ireplace('</ol>', '[/list]', $string);
        $string = \str_ireplace('</ul>', '[/list]', $string);
        $string = \str_ireplace('<ul>', '[list]', $string);
        $string = \str_ireplace("<ol type='1'>", '[list=1]', $string);
        $string = \str_ireplace("<ol>", '[list=1]', $string);
        $string = \str_ireplace('<li>', '[*]', $string);
        $string = \str_ireplace('</li>', '', $string);

        // images
        $string = \preg_replace('~<img[^>]+src=["\']([^"\']+)["\'][^>]*/?>~is', '[img]\\1[/img]', $string);

        // youtube
        $string = \preg_replace('~<iframe[^>]*src="https://www.youtube(?:-nocookie)?.com/embed/([a-zA-Z0-9_-]+)[^"]*"[^>]*></iframe>~is', '[media]https://www.youtube.com/watch?v=\\1[/media]', $string);

        // embedded content (Spotify, X/Twitter, SoundCloud, etc.)
        $string = \preg_replace_callback(
            '~<iframe[^>]*?\s(?:data-embed-)?src="[^"]*?controller=embed[^"]*?(?:&amp;|&)url=([^"]+)"[^>]*>\s*</iframe>~is',
            static function ($matches) {
                $url = StringUtil::decodeHTML($matches[1]);
                if (\preg_match('~^https?%3A~i', $url)) {
                    $url = \rawurldecode($url);
                }
                if (!\preg_match('~^https?://~i', $url)) {
                    return '';
                }

                // the Twitter media provider only accepts `https://twitter.com/` and `https://x.com/`
                $url = \preg_replace('~^https?://(?:www\.|mobile\.)?(twitter|x)\.com/~i', 'https://\\1.com/', $url);

                // the message is decoded once more at the end
                $encodedUrl = StringUtil::encodeHTML($url);
                if (BBCodeMediaProvider::isMediaURL($url)) {
                    return '[media]' . $encodedUrl . '[/media]';
                }

                return '[url]' . $encodedUrl . '[/url]';
            },
            $string
        );

        // strip tags
        $string = StringUtil::stripHTML($string);

        // decode html entities
        $string = StringUtil::decodeHTML($string);

        return StringUtil::trim($string);
    }
}
