<?php

namespace wcf\system\exporter;

use wbb\data\board\Board;
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
 * @package WoltLabSuite\Core\System\Exporter
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
    ];

    /**
     * @inheritDoc
     */
    protected $limits = [
        'com.woltlab.wcf.user' => 200,
        'com.woltlab.wcf.user.avatar' => 100,
        'com.woltlab.wcf.user.follower' => 100,
        'com.woltlab.gallery.image' => 100,
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
        $statement = $this->database->prepareStatement($sql);
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
        $statement = $this->database->prepareStatement($sql);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $profileFields[] = $row;
        }

        // prepare password update
        $sql = "UPDATE  wcf" . WCF_N . "_user
                SET     password = ?
                WHERE   userID = ?";
        $passwordUpdateStatement = WCF::getDB()->prepareStatement($sql);

        // get users
        $sql = "SELECT      pfields_content.*, members.*
                FROM        " . $this->databasePrefix . "core_members members
                LEFT JOIN   " . $this->databasePrefix . "core_pfields_content pfields_content
                ON          pfields_content.member_id = members.member_id
                WHERE       members.member_id BETWEEN ? AND ?
                ORDER BY    members.member_id";
        $statement = $this->database->prepareStatement($sql);
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
                'profileHits' => $row['members_profile_views'],
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
            if ($newUserID) {
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
        $statement = $this->database->prepareStatement($sql);
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
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $data = [
                'categoryName' => 'profile.personal',
                'optionType' => 'textarea',
                'askDuringRegistration' => $row['pf_show_on_reg'],
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
        $statement = $this->database->prepareStatement($sql);
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
        $statement = $this->database->prepareStatement($sql);
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
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $avatarName = \basename($row['pp_main_photo']);
            $source = $this->fileSystemPath . 'uploads/' . $row['pp_main_photo'];
            $avatarExtension = \pathinfo($avatarName, \PATHINFO_EXTENSION);

            $data = [
                'avatarName' => $avatarName,
                'avatarExtension' => $avatarExtension,
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
        $statement = $this->database->prepareStatement($sql);
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
        $statement = $this->database->prepareStatement($sql);
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
        $statement = $this->database->prepareStatement($sql);
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
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
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
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'subject' => $row['mt_title'],
                'time' => $row['mt_date'],
                'userID' => $row['mt_starter_id'] ?: null,
                'username' => $row['mt_is_system'] ? 'System' : ($row['name'] ?: ''),
                'isDraft' => $row['mt_is_draft'],
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
        $statement = $this->database->prepareStatement($sql);
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
        $statement = $this->database->prepareStatement($sql);
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
        $statement = $this->database->prepareStatement($sql);
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
        $statement = $this->database->prepareStatement($sql);
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
        $statement = $this->database->prepareStatement($sql);
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
        $statement = $this->database->prepareStatement($sql);
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
                'isDisabled' => $row['approved'] == 0 ? 1 : 0,
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
        return $this->__getMaxID($this->databasePrefix . "forums_posts", 'pid');
    }

    /**
     * Exports posts.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportPosts($offset, $limit)
    {
        $sql = "SELECT      *
                FROM        " . $this->databasePrefix . "forums_posts
                WHERE       pid BETWEEN ? AND ?
                ORDER BY    pid";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'threadID' => $row['topic_id'],
                'userID' => $row['author_id'],
                'username' => $row['author_name'],
                'message' => self::fixMessage($row['post']),
                'time' => $row['post_date'],
                'isDeleted' => ($row['queued'] == 3) ? 1 : 0,
                'isDisabled' => ($row['queued'] == 2) ? 1 : 0,
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
        $statement = $this->database->prepareStatement($sql);
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
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
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
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            if (!$row['topic_firstpost']) {
                continue;
            }

            try {
                $data = JSON::decode($row['choices']);
            } catch (SystemException $e) {
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
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            try {
                $data = JSON::decode($row['member_choices']);
            } catch (SystemException $e) {
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
        $statement = $this->database->prepareStatement($sql);
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
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
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
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "core_attachments_map
                WHERE   location_key = ?
                    AND id2 IS NOT NULL";
        $statement = $this->database->prepareStatement($sql);
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
        $sql = "SELECT      core_attachments.*, core_attachments_map.id2
                FROM        " . $this->databasePrefix . "core_attachments_map core_attachments_map
                LEFT JOIN   " . $this->databasePrefix . "core_attachments core_attachments
                ON          core_attachments.attach_id = core_attachments_map.attachment_id
                WHERE       core_attachments_map.location_key = ?
                        AND core_attachments_map.id2 IS NOT NULL
                ORDER BY    core_attachments_map.attachment_id";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute([$type]);
        while ($row = $statement->fetchArray()) {
            if (!$row['attach_id']) {
                continue; // skip orphaned attachments
            }

            $fileLocation = $this->fileSystemPath . 'uploads/' . $row['attach_location'];

            $data = [
                'objectID' => $row['id2'],
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
        $statement = $this->database->prepareStatement($sql);
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
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
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
        $statement = $this->database->prepareStatement($sql);
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
        $statement = $this->database->prepareStatement($sql);
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
        $statement = $this->database->prepareStatement($sql);
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
        $statement = $this->database->prepareStatement($sql);
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
        $statement = $this->database->prepareStatement($sql);
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
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
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
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'userID' => $row['blog_member_id'],
                'username' => $row['username'],
                'title' => $this->getLanguageVar('blogs_blog', $row['blog_id']),
                'description' => self::fixMessage($this->getLanguageVar('blogs_blog', $row['blog_id'], 'desc')),
                'isFeatured' => $row['blog_pinned'],
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
                FROM    " . $this->databasePrefix . "blog_entry_categories";
        $statement = $this->database->prepareStatement($sql);
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
                FROM        " . $this->databasePrefix . "blog_entry_categories
                ORDER BY    entry_category_id";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $data = [
                'title' => $row['entry_category_name'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.blog.category')
                ->import($row['entry_category_id'], $data);
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
        $statement = $this->database->prepareStatement($sql);
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

        $sql = "SELECT      *
                FROM        " . $this->databasePrefix . "blog_entries
                " . $conditionBuilder;
        $statement = $this->database->prepareStatement($sql);
        $statement->execute($conditionBuilder->getParameters());
        while ($row = $statement->fetchArray()) {
            $additionalData = [];
            if (isset($tags[$row['entry_id']])) {
                $additionalData['tags'] = $tags[$row['entry_id']];
            }
            if ($row['entry_category_id']) {
                $additionalData['categories'] = [$row['entry_category_id']];
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
        $statement = $this->database->prepareStatement($sql);
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
        $statement = $this->database->prepareStatement($sql);
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
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
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
        $statement = $this->database->prepareStatement($sql);
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
            $statement = $this->database->prepareStatement($sql);
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
            $sql = "SELECT  word_custom
                    FROM    " . $this->databasePrefix . "core_sys_lang_words
                    WHERE   lang_id = ?
                        AND word_key = ?";
            $this->languageStatement = $this->database->prepareStatement($sql, 1);
        }
        $this->languageStatement->execute([
            $this->getDefaultLanguageID(),
            ($prefix . '_' . $id . ($suffix ? ('_' . $suffix) : '')),
        ]);
        $row = $this->languageStatement->fetchArray();
        if ($row !== false) {
            return $row['word_custom'];
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
            '~<blockquote[^>]*>(.*?)</blockquote>~is',
            '[quote]\\1[/quote]',
            $string
        );

        // replace base_url placeholder in urls/images
        $string = \str_ireplace('<___base_url___>/', WCF::getPath(), $string);

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

        // strip tags
        $string = StringUtil::stripHTML($string);

        // decode html entities
        $string = StringUtil::decodeHTML($string);

        return StringUtil::trim($string);
    }
}
