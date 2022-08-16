<?php

namespace wcf\system\exporter;

use wbb\data\board\Board;
use wcf\data\like\Like;
use wcf\data\user\group\UserGroup;
use wcf\data\user\UserProfile;
use wcf\system\database\util\PreparedStatementConditionBuilder;
use wcf\system\importer\ImportHandler;
use wcf\system\WCF;
use wcf\util\StringUtil;
use wcf\util\UserUtil;

/**
 * Exporter for IP.Board 3.x
 *
 * @author  Marcel Werk
 * @copyright   2001-2019 WoltLab GmbH
 * @license GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package WoltLabSuite\Core\System\Exporter
 */
class IPB3xExporter extends AbstractExporter
{
    protected static $knownProfileFields = ['website', 'icq', 'gender', 'location', 'interests', 'skype'];

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
                FROM    " . $this->databasePrefix . "core_like";
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

        // gallery
        if (\in_array('com.woltlab.gallery.image', $this->selectedData)) {
            if (\in_array('com.woltlab.gallery.category', $this->selectedData)) {
                $queue[] = 'com.woltlab.gallery.category';
            }
            if (\in_array('com.woltlab.gallery.album', $this->selectedData)) {
                $queue[] = 'com.woltlab.gallery.album';
            }
            $queue[] = 'com.woltlab.gallery.image';
        }

        return $queue;
    }

    /**
     * Counts users.
     */
    public function countUsers()
    {
        return $this->__getMaxID($this->databasePrefix . "members", 'member_id');
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
        $profileFields = $knownProfileFields = [];
        $sql = "SELECT  *
                FROM    " . $this->databasePrefix . "pfields_data";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            if (\in_array($row['pf_key'], self::$knownProfileFields)) {
                $knownProfileFields[$row['pf_key']] = $row;
            } else {
                $profileFields[] = $row;
            }
        }

        // prepare password update
        $sql = "UPDATE  wcf" . WCF_N . "_user
                SET     password = ?
                WHERE   userID = ?";
        $passwordUpdateStatement = WCF::getDB()->prepareStatement($sql);

        // get users
        $sql = "SELECT      pfields_content.*, members.*, profile_portal.*
                FROM        " . $this->databasePrefix . "members members
                LEFT JOIN   " . $this->databasePrefix . "profile_portal profile_portal
                ON          profile_portal.pp_member_id = members.member_id
                LEFT JOIN   " . $this->databasePrefix . "pfields_content pfields_content
                ON          pfields_content.member_id = members.member_id
                WHERE       members.member_id BETWEEN ? AND ?
                ORDER BY    members.member_id";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'username' => self::fixSubject($row['name']),
                'password' => null,
                'email' => $row['email'],
                'registrationDate' => $row['joined'],
                'banned' => $row['member_banned'],
                'registrationIpAddress' => UserUtil::convertIPv4To6($row['ip_address']),
                'signature' => self::fixMessage($row['signature']),
                'profileHits' => $row['members_profile_views'],
                'userTitle' => $row['title'] ?: '',
                'lastActivityTime' => $row['last_activity'],
            ];

            // get group ids
            $groupIDs = \preg_split('/,/', $row['mgroup_others'], -1, \PREG_SPLIT_NO_EMPTY);
            $groupIDs[] = $row['member_group_id'];

            // get user options
            $options = [
                //'timezone' => $row['time_offset'],
                'homepage' => (isset($knownProfileFields['website']) && !empty($row['field_' . $knownProfileFields['website']['pf_id']])) ? $row['field_' . $knownProfileFields['website']['pf_id']] : '',
                'icq' => (isset($knownProfileFields['icq']) && !empty($row['field_' . $knownProfileFields['icq']['pf_id']])) ? $row['field_' . $knownProfileFields['icq']['pf_id']] : '',
                'hobbies' => (isset($knownProfileFields['interests']) && !empty($row['field_' . $knownProfileFields['interests']['pf_id']])) ? $row['field_' . $knownProfileFields['interests']['pf_id']] : '',
                'skype' => (isset($knownProfileFields['skype']) && !empty($row['field_' . $knownProfileFields['skype']['pf_id']])) ? $row['field_' . $knownProfileFields['skype']['pf_id']] : '',
                'location' => (isset($knownProfileFields['location']) && !empty($row['field_' . $knownProfileFields['location']['pf_id']])) ? $row['field_' . $knownProfileFields['location']['pf_id']] : '',
            ];

            // get birthday
            if ($row['bday_day'] && $row['bday_month'] && $row['bday_year']) {
                $options['birthday'] = \sprintf(
                    '%04d-%02d-%02d',
                    $row['bday_year'],
                    $row['bday_month'],
                    $row['bday_day']
                );
            }

            // get gender
            if (
                isset($knownProfileFields['gender'])
                && !empty($row['field_' . $knownProfileFields['gender']['pf_id']])
            ) {
                $gender = $row['field_' . $knownProfileFields['gender']['pf_id']];
                if ($gender == 'm') {
                    $options['gender'] = UserProfile::GENDER_MALE;
                }
                if ($gender == 'f') {
                    $options['gender'] = UserProfile::GENDER_FEMALE;
                }
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
                $passwordUpdateStatement->execute([
                    'ipb3:' . $row['members_pass_hash'] . ':' . $row['members_pass_salt'],
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
        $conditionBuilder = new PreparedStatementConditionBuilder();
        $conditionBuilder->add('pf_key NOT IN (?)', [self::$knownProfileFields]);

        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "pfields_data
                " . $conditionBuilder;
        $statement = $this->database->prepareStatement($sql);
        $statement->execute($conditionBuilder->getParameters());
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
        $conditionBuilder = new PreparedStatementConditionBuilder();
        $conditionBuilder->add('pf_key NOT IN (?)', [self::$knownProfileFields]);

        $sql = "SELECT      *
                FROM        " . $this->databasePrefix . "pfields_data
                " . $conditionBuilder . "
                ORDER BY    pf_id";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute($conditionBuilder->getParameters());
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
                    ['name' => $row['pf_title']]
                );
        }
    }

    /**
     * Counts user groups.
     */
    public function countUserGroups()
    {
        return $this->__getMaxID("`" . $this->databasePrefix . "groups`", 'g_id');
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
                FROM        `" . $this->databasePrefix . "groups`
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
                'groupName' => $row['g_title'],
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
        $sql = "SELECT  MAX(pp_member_id) AS maxID
                FROM    " . $this->databasePrefix . "profile_portal
                WHERE   avatar_location <> ''
                    OR pp_main_photo <> ''";
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
                FROM        " . $this->databasePrefix . "profile_portal
                WHERE       pp_member_id BETWEEN ? AND ?
                        AND (
                                avatar_location <> ''
                             OR pp_main_photo <> ''
                             )
                ORDER BY    pp_member_id";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            if ($row['pp_main_photo']) {
                $avatarName = \basename($row['pp_main_photo']);

                $source = $this->fileSystemPath . 'uploads/' . $row['pp_main_photo'];
            } else {
                $avatarName = \basename($row['avatar_location']);

                $source = '';
                if ($row['avatar_type'] != 'url') {
                    $source = $this->fileSystemPath;
                    if ($row['avatar_type'] == 'upload') {
                        $source .= 'uploads/';
                    } else {
                        $source .= 'style_avatars/';
                    }
                }
                $source .= $row['avatar_location'];
            }

            $avatarExtension = \pathinfo($avatarName, \PATHINFO_EXTENSION);

            $data = [
                'avatarName' => $avatarName,
                'avatarExtension' => $avatarExtension,
                'userID' => $row['pp_member_id'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.user.avatar')
                ->import(
                    $row['pp_member_id'],
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
        return $this->__getMaxID($this->databasePrefix . "member_status_updates", 'status_id');
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
                FROM        " . $this->databasePrefix . "member_status_updates status_updates
                LEFT JOIN   " . $this->databasePrefix . "members members
                ON          members.member_id = status_updates.status_author_id
                WHERE       status_updates.status_id BETWEEN ? AND ?
                ORDER BY    status_updates.status_id";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'objectID' => $row['status_member_id'],
                'userID' => $row['status_author_id'],
                'username' => $row['name'] ? self::fixSubject($row['name']) : '',
                'message' => self::fixStatusUpdate($row['status_content']),
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
        return $this->__getMaxID($this->databasePrefix . "member_status_replies", 'reply_id');
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
                FROM        " . $this->databasePrefix . "member_status_replies member_status_replies
                LEFT JOIN   " . $this->databasePrefix . "members members
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
                'username' => $row['name'] ? self::fixSubject($row['name']) : '',
                'message' => self::fixStatusUpdate($row['reply_content']),
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
        return $this->__getMaxID($this->databasePrefix . "profile_friends", 'friends_id');
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
                FROM        " . $this->databasePrefix . "profile_friends
                WHERE       friends_id BETWEEN ? AND ?
                ORDER BY    friends_id";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'userID' => $row['friends_member_id'],
                'followUserID' => $row['friends_friend_id'],
                'time' => $row['friends_added'],
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
        return $this->__getMaxID($this->databasePrefix . "message_topics", 'mt_id');
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
                FROM        " . $this->databasePrefix . "message_topics message_topics
                LEFT JOIN   " . $this->databasePrefix . "members members
                ON          members.member_id = message_topics.mt_starter_id
                WHERE       message_topics.mt_id BETWEEN ? AND ?
                ORDER BY    message_topics.mt_id";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'subject' => self::fixSubject($row['mt_title']),
                'time' => $row['mt_date'],
                'userID' => $row['mt_starter_id'] ?: null,
                'username' => $row['mt_is_system'] ? 'System' : ($row['name'] ? self::fixSubject($row['name']) : ''),
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
        return $this->__getMaxID($this->databasePrefix . "message_posts", 'msg_id');
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
                FROM        " . $this->databasePrefix . "message_posts message_posts
                LEFT JOIN   " . $this->databasePrefix . "members members
                ON          members.member_id = message_posts.msg_author_id
                WHERE       message_posts.msg_id BETWEEN ? AND ?
                ORDER BY    message_posts.msg_id";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'conversationID' => $row['msg_topic_id'],
                'userID' => $row['msg_author_id'] ?: null,
                'username' => $row['name'] ? self::fixSubject($row['name']) : '',
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
        return $this->__getMaxID($this->databasePrefix . "message_topic_user_map", 'map_id');
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
                FROM        " . $this->databasePrefix . "message_topic_user_map message_topic_user_map
                LEFT JOIN   " . $this->databasePrefix . "members members
                ON          members.member_id = message_topic_user_map.map_user_id
                WHERE       message_topic_user_map.map_id BETWEEN ? AND ?
                ORDER BY    message_topic_user_map.map_id";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'conversationID' => $row['map_topic_id'],
                'participantID' => $row['map_user_id'],
                'username' => $row['name'] ? self::fixSubject($row['name']) : '',
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
        return $this->countAttachments('msg');
    }

    /**
     * Exports conversation attachments.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportConversationAttachments($offset, $limit)
    {
        $this->exportAttachments('msg', 'com.woltlab.wcf.conversation.attachment', $offset, $limit);
    }

    /**
     * Counts boards.
     */
    public function countBoards()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "forums";
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
                FROM        " . $this->databasePrefix . "forums
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
                'title' => self::fixSubject($board['name']),
                'description' => $board['description'],
                'externalURL' => $board['redirect_url'],
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
        return $this->__getMaxID($this->databasePrefix . "topics", 'tid');
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
                FROM        " . $this->databasePrefix . "topics
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
        $tags = $this->getTags('forums', 'topics', $threadIDs);

        // get threads
        $conditionBuilder = new PreparedStatementConditionBuilder();
        $conditionBuilder->add('topics.tid IN (?)', [$threadIDs]);

        $sql = "SELECT  topics.*
                FROM    " . $this->databasePrefix . "topics topics
                " . $conditionBuilder;
        $statement = $this->database->prepareStatement($sql);
        $statement->execute($conditionBuilder->getParameters());
        while ($row = $statement->fetchArray()) {
            $data = [
                'boardID' => $row['forum_id'],
                'topic' => self::fixSubject($row['title']),
                'time' => $row['start_date'],
                'userID' => $row['starter_id'],
                'username' => self::fixSubject($row['starter_name']),
                'views' => $row['views'],
                'isSticky' => $row['pinned'],
                'isDisabled' => $row['approved'] == 0 ? 1 : 0,
                'isClosed' => $row['state'] == 'close' ? 1 : 0,
                'isDeleted' => $row['tdelete_time'] ? 1 : 0,
                'movedThreadID' => $row['moved_to'] ? \intval($row['moved_to']) : null,
                'movedTime' => $row['moved_on'],
                'deleteTime' => $row['tdelete_time'],
                'lastPostTime' => $row['last_post'],
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
        return $this->__getMaxID($this->databasePrefix . "posts", 'pid');
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
                FROM        " . $this->databasePrefix . "posts
                WHERE       pid BETWEEN ? AND ?
                ORDER BY    pid";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'threadID' => $row['topic_id'],
                'userID' => $row['author_id'],
                'username' => $row['author_name'] ? self::fixSubject($row['author_name']) : '',
                'message' => self::fixMessage($row['post']),
                'time' => $row['post_date'],
                'isDeleted' => $row['queued'] == 3 ? 1 : 0,
                'isDisabled' => $row['queued'] == 2 ? 1 : 0,
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
                FROM    " . $this->databasePrefix . "core_like
                WHERE   like_app = ?
                    AND like_area = ?";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute(['forums', 'topics']);
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
                FROM        " . $this->databasePrefix . "core_like
                WHERE       like_app = ?
                        AND like_area = ?
                ORDER BY    like_id";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute(['forums', 'topics']);
        while ($row = $statement->fetchArray()) {
            $data = [
                'objectID' => $row['like_rel_id'],
                'userID' => $row['like_member_id'],
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
        return $this->__getMaxID($this->databasePrefix . "polls", 'pid');
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
                FROM        " . $this->databasePrefix . "polls polls
                LEFT JOIN   " . $this->databasePrefix . "topics topics
                ON          topics.tid = polls.tid
                WHERE       pid BETWEEN ? AND ?
                ORDER BY    pid";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = @\unserialize($row['choices']);
            if (!$data) {
                $data = @\unserialize(\str_replace('\"', '"', $row['choices'])); // pre ipb3.4 fallback
            }
            if (!$data || !isset($data[1])) {
                continue;
            }

            // import poll
            $pollData = [
                'objectID' => $row['topic_firstpost'],
                'question' => self::fixSubject($data[1]['question']),
                'time' => $row['start_date'],
                'isPublic' => $row['poll_view_voters'],
                'maxVotes' => !empty($data[1]['multi']) ? \count($data[1]['choice']) : 1,
                'votes' => $row['votes'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.poll')
                ->import($row['pid'], $pollData);

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
        return $this->__getMaxID($this->databasePrefix . "voters", 'vid');
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
                FROM        " . $this->databasePrefix . "voters voters
                LEFT JOIN   " . $this->databasePrefix . "polls polls
                ON          polls.tid = voters.tid
                WHERE       voters.vid BETWEEN ? AND ?
                ORDER BY    voters.vid";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = @\unserialize($row['member_choices']);
            if (!$data) {
                $data = @\unserialize(\str_replace('\"', '"', $row['member_choices'])); // pre ipb3.4 fallback
            }
            if (!$data || !isset($data[1])) {
                continue;
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
                FROM    " . $this->databasePrefix . "core_like
                WHERE   like_app = ?
                    AND like_area = ?
                    AND like_visible = ?";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute(['forums', 'topics', 1]);
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
        $sql = "SELECT      core_like.*, topics.topic_firstpost, topics.starter_id
                FROM        " . $this->databasePrefix . "core_like core_like
                LEFT JOIN   " . $this->databasePrefix . "topics topics
                ON          topics.tid = core_like.like_rel_id
                WHERE       core_like.like_app = ?
                        AND core_like.like_area = ?
                        AND core_like.like_visible = ?
                ORDER BY    core_like.like_id";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute(['forums', 'topics', 1]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'objectID' => $row['topic_firstpost'],
                'objectUserID' => $row['starter_id'] ?: null,
                'userID' => $row['like_member_id'],
                'likeValue' => Like::LIKE,
                'time' => $row['like_added'],
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
        return $this->countAttachments('post');
    }

    /**
     * Exports post attachments.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportPostAttachments($offset, $limit)
    {
        $this->exportAttachments('post', 'com.woltlab.wbb.attachment', $offset, $limit);
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
                FROM    " . $this->databasePrefix . "attachments
                WHERE   attach_rel_module = ?
                    AND attach_rel_id > ?";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$type, 0]);
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
        $sql = "SELECT      *
                FROM        " . $this->databasePrefix . "attachments
                WHERE       attach_rel_module = ?
                        AND attach_rel_id > ?
                ORDER BY    attach_id DESC";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute([$type, 0]);
        while ($row = $statement->fetchArray()) {
            $fileLocation = $this->fileSystemPath . 'uploads/' . $row['attach_location'];

            $data = [
                'objectID' => $row['attach_rel_id'],
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
                'title' => $row['category_name'],
                'description' => $row['category_description'],
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
                LEFT JOIN   " . $this->databasePrefix . "members members
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
                'description' => StringUtil::stripHTML($row['album_description']),
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
        $sql = "SELECT      images.*, members.name AS username
                FROM        " . $this->databasePrefix . "gallery_images images
                LEFT JOIN   " . $this->databasePrefix . "members members
                ON          members.member_id = images.image_member_id
                WHERE       image_id BETWEEN ? AND ?
                ORDER BY    image_id";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $fileLocation = $this->fileSystemPath . 'uploads/' . $row['image_directory'] . '/' . $row['image_masked_file_name'];
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

        // <br /> to newline
        $string = \str_ireplace('<br />', "\n", $string);
        $string = \str_ireplace('<br>', "\n", $string);
        $string = \str_ireplace('</p>', "\n", $string);

        // decode html entities
        $string = StringUtil::decodeHTML($string);

        // replace single quote entity
        $string = \str_replace('&#39;', "'", $string);

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

        // strike
        $string = \str_ireplace('<strike>', '[s]', $string);
        $string = \str_ireplace('</strike>', '[/s]', $string);

        // font face
        $string = \preg_replace_callback(
            '~<span style="font-family:(.*?)">(.*?)</span>~is',
            static function ($matches) {
                $font = \str_replace(";", '', \str_replace("'", '', $matches[1]));

                return "[font='" . $font . "']" . $matches[2] . "[/font]";
            },
            $string
        );

        // font size
        $string = \preg_replace(
            '~<span style="font-size:(\d+)px;">(.*?)</span>~is',
            '[size=\\1]\\2[/size]',
            $string
        );

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

        // align
        $string = \preg_replace(
            '~<p style="text-align:(left|center|right);">(.*?)</p>~is',
            '[align=\\1]\\2[/align]',
            $string
        );
        $string = \preg_replace(
            '~<p class="bbc_center">(.*?)</p>~is',
            '[align=center]\\1[/align]',
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

        // mails
        $string = \preg_replace(
            '~<a.*?href=(?:"|\')mailto:([^"]*)(?:"|\').*?>(.*?)</a>~is',
            '[email=\'\\1\']\\2[/email]',
            $string
        );

        // urls
        $string = \preg_replace(
            '~<a.*?href=(?:"|\')([^"\']*)(?:"|\').*?>(.*?)</a>~is',
            '[url=\'\\1\']\\2[/url]',
            $string
        );

        // smileys
        $string = \preg_replace(
            '~<img src=\'[^\']+\' class=\'bbc_emoticon\' alt=\'([^\']+)\' ?/?>~is',
            '\\1',
            $string
        );

        // images
        $string = \preg_replace('~<img[^>]+src=["\']([^"\']+)["\'][^>]*/?>~is', '[img]\\1[/img]', $string);

        // quotes
        $string = \preg_replace(
            '~<blockquote[^>]*data-author="([^"]+)"[^>]*>(.*?)</blockquote>~is',
            "[quote='\\1']\\2[/quote]",
            $string
        );
        $string = \preg_replace('~<blockquote[^>]*>(.*?)</blockquote>~is', '[quote]\\1[/quote]', $string);

        // code
        for ($i = 0, $length = \count($codes); $i < $length; $i++) {
            $string = \str_replace('@@@WCF_CODE_BLOCK_' . $i . '@@@', '[code]' . $codes[$i] . '[/code]', $string);
        }

        // embedded attachments
        $string = \preg_replace('~\[attachment=(\d+):[^\]]*\]~i', '[attach]\\1[/attach]', $string);

        // [center] => [align=center]
        $string = \str_ireplace('[center]', '[align=center]', $string);
        $string = \str_ireplace('[/center]', '[/align]', $string);

        // [font=""] => [font='']
        $string = \preg_replace('~\[([a-z]+)="(.*?)"\]~i', "[\\1='\\2']", $string);

        // fix quote tags
        $string = \preg_replace('~\[quote name=\'([^\']+)\'.*?\]~si', "[quote='\\1']", $string);
        $string = \preg_replace('~\[quote name="([^\']+)".*?\]~si', "[quote='\\1']", $string);

        // fix size bbcodes
        $string = \preg_replace_callback(
            '/\[size=\'?(\d+)\'?\]/i',
            static function ($matches) {
                $size = 10;

                switch ($matches[1]) {
                    case 1:
                        $size = 8;
                        break;
                    case 2:
                        $size = 10;
                        break;
                    case 3:
                        $size = 12;
                        break;
                    case 4:
                        $size = 14;
                        break;
                    case 5:
                        $size = 18;
                        break;
                    case 6:
                        $size = 24;
                        break;
                    case 7:
                        $size = 36;
                        break;
                }

                return '[size=' . $size . ']';
            },
            $string
        );

        // remove html comments
        $string = \preg_replace('/<\!--.*?-->/is', '', $string);

        // remove obsolete code
        $string = \str_ireplace('<p>&nbsp;</p>', '', $string);
        $string = \str_ireplace('<p>', '', $string);
        $string = \str_ireplace('</p>', '', $string);
        $string = \str_ireplace('<div>', '', $string);
        $string = \str_ireplace('</div>', '', $string);
        $string = \str_ireplace('</span>', '', $string);
        $string = \preg_replace('~<span.*?>~', '', $string);

        return $string;
    }

    /**
     * Returns subject with encoding as used in WCF.
     *
     * @param   string      $string
     * @return  string
     */
    private static function fixSubject($string)
    {
        // decode html entities
        $string = StringUtil::decodeHTML($string);

        // replace single quote entity
        return \str_replace('&#39;', "'", $string);
    }

    /**
     * Returns status update with encoding as used in WCF.
     *
     * @param   string      $string
     * @return  string
     */
    private static function fixStatusUpdate($string)
    {
        // <br /> to newline
        $string = \str_ireplace('<br />', "\n", $string);
        $string = \str_ireplace('<br>', "\n", $string);

        // decode html entities
        $string = StringUtil::decodeHTML($string);

        // replace single quote entity
        return \str_replace('&#39;', "'", $string);
    }
}
