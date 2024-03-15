<?php

namespace wcf\system\exporter;

use wbb\data\board\Board;
use wbb\data\board\BoardCache;
use wcf\data\user\group\UserGroup;
use wcf\data\user\option\UserOption;
use wcf\system\database\util\PreparedStatementConditionBuilder;
use wcf\system\importer\ImportHandler;
use wcf\system\option\user\SelectOptionsUserOptionOutput;
use wcf\system\WCF;
use wcf\util\FileUtil;
use wcf\util\MessageUtil;
use wcf\util\StringUtil;
use wcf\util\UserUtil;

/**
 * Exporter for phpBB 3.0.x
 *
 * @author  Tim Duesterhus
 * @copyright   2001-2019 WoltLab GmbH
 * @license GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 */
final class PhpBB3xExporter extends AbstractExporter
{
    private const TOPIC_TYPE_GLOBAL = 3;

    private const TOPIC_TYPE_ANNOUCEMENT = 2;

    private const TOPIC_TYPE_STICKY = 1;

    private const TOPIC_TYPE_DEFAULT = 0;

    private const TOPIC_STATUS_LINK = 2;

    private const TOPIC_STATUS_CLOSED = 1;

    private const TOPIC_STATUS_DEFAULT = 0;

    private const USER_TYPE_USER_IGNORE = 2;

    private const AVATAR_TYPE_GALLERY = 3;

    private const AVATAR_TYPE_REMOTE = 2;

    private const AVATAR_TYPE_UPLOADED = 1;

    private const AVATAR_TYPE_NO_AVATAR = 0;

    private const BOARD_TYPE_LINK = 2;

    private const BOARD_TYPE_BOARD = 1;

    private const BOARD_TYPE_CATEGORY = 0;

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
        'com.woltlab.wcf.user.rank' => 'UserRanks',
        'com.woltlab.wcf.user.follower' => 'Followers',
        'com.woltlab.wcf.user.avatar' => 'UserAvatars',
        'com.woltlab.wcf.user.option' => 'UserOptions',
        'com.woltlab.wcf.conversation.label' => 'ConversationFolders',
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
        'com.woltlab.wbb.poll.option' => 'PollOptions',
        'com.woltlab.wbb.poll.option.vote' => 'PollOptionVotes',
        'com.woltlab.wbb.acl' => 'ACLs',
        'com.woltlab.wcf.smiley' => 'Smilies',
    ];

    /**
     * @inheritDoc
     */
    protected $limits = [
        'com.woltlab.wcf.user' => 200,
        'com.woltlab.wcf.user.avatar' => 100,
        'com.woltlab.wcf.conversation.attachment' => 100,
        'com.woltlab.wbb.thread' => 200,
        'com.woltlab.wbb.attachment' => 100,
        'com.woltlab.wbb.acl' => 1,
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
                'com.woltlab.wcf.user.follower',
                'com.woltlab.wcf.user.rank',
            ],
            'com.woltlab.wbb.board' => [
                'com.woltlab.wbb.acl',
                'com.woltlab.wbb.attachment',
                'com.woltlab.wbb.poll',
                'com.woltlab.wbb.watchedThread',
            ],
            'com.woltlab.wcf.conversation' => [
                'com.woltlab.wcf.conversation.attachment',
                'com.woltlab.wcf.conversation.label',
            ],
            'com.woltlab.wcf.smiley' => [],
        ];
    }

    /**
     * @inheritDoc
     */
    public function validateDatabaseAccess()
    {
        parent::validateDatabaseAccess();

        $sql = "SELECT  COUNT(*)
                FROM    " . $this->databasePrefix . "zebra";
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
            || \in_array('com.woltlab.wcf.smiley', $this->selectedData)
        ) {
            if (
                empty($this->fileSystemPath)
                || !@\file_exists($this->fileSystemPath . 'includes/error_collector.php')
            ) {
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
                if (\in_array('com.woltlab.wcf.user.rank', $this->selectedData)) {
                    $queue[] = 'com.woltlab.wcf.user.rank';
                }
            }
            if (\in_array('com.woltlab.wcf.user.option', $this->selectedData)) {
                $queue[] = 'com.woltlab.wcf.user.option';
            }
            $queue[] = 'com.woltlab.wcf.user';
            if (\in_array('com.woltlab.wcf.user.avatar', $this->selectedData)) {
                $queue[] = 'com.woltlab.wcf.user.avatar';
            }

            if (\in_array('com.woltlab.wcf.user.follower', $this->selectedData)) {
                $queue[] = 'com.woltlab.wcf.user.follower';
            }

            // conversation
            if (\in_array('com.woltlab.wcf.conversation', $this->selectedData)) {
                if (\in_array('com.woltlab.wcf.conversation.label', $this->selectedData)) {
                    $queue[] = 'com.woltlab.wcf.conversation.label';
                }

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

            if (\in_array('com.woltlab.wbb.acl', $this->selectedData)) {
                $queue[] = 'com.woltlab.wbb.acl';
            }
            if (\in_array('com.woltlab.wbb.attachment', $this->selectedData)) {
                $queue[] = 'com.woltlab.wbb.attachment';
            }
            if (\in_array('com.woltlab.wbb.watchedThread', $this->selectedData)) {
                $queue[] = 'com.woltlab.wbb.watchedThread';
            }
            if (\in_array('com.woltlab.wbb.poll', $this->selectedData)) {
                $queue[] = 'com.woltlab.wbb.poll';
                $queue[] = 'com.woltlab.wbb.poll.option';
                $queue[] = 'com.woltlab.wbb.poll.option.vote';
            }
        }

        // smiley
        if (\in_array('com.woltlab.wcf.smiley', $this->selectedData)) {
            $queue[] = 'com.woltlab.wcf.smiley';
        }

        return $queue;
    }

    /**
     * @inheritDoc
     */
    public function getDefaultDatabasePrefix()
    {
        return 'phpbb_';
    }

    /**
     * Counts user groups.
     */
    public function countUserGroups()
    {
        return $this->__getMaxID($this->databasePrefix . "groups", 'group_id');
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
                FROM        " . $this->databasePrefix . "groups
                WHERE       group_id BETWEEN ? AND ?
                ORDER BY    group_id";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            switch ($row['group_id']) {
                case 1:
                    $groupType = UserGroup::GUESTS;
                    break;
                case 2:
                    $groupType = UserGroup::USERS;
                    break;
                case 6:
                    // BOTS
                    continue 2;
                default:
                    $groupType = UserGroup::OTHER;
                    break;
            }

            $userOnlineMarking = '%s';
            if ($row['group_colour']) {
                $userOnlineMarking = '<span style="color: #' . $row['group_colour'] . '">%s</span>';
            }

            $data = [
                'groupName' => $row['group_name'],
                'groupType' => $groupType,
                'userOnlineMarking' => $userOnlineMarking,
                'showOnTeamPage' => $row['group_legend'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.user.group')
                ->import($row['group_id'], $data);
        }
    }

    /**
     * Counts users.
     */
    public function countUsers()
    {
        $sql = "SELECT  MAX(user_id) AS maxID
                FROM    " . $this->databasePrefix . "users
                WHERE   user_type <> ?";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([self::USER_TYPE_USER_IGNORE]);
        $row = $statement->fetchArray();
        if ($row !== false) {
            return $row['maxID'];
        }

        return 0;
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
                FROM    " . $this->databasePrefix . "profile_fields";
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
        $sql = "SELECT      fields_table.*, user_table.*, ban_table.ban_give_reason AS banReason,
                            (
                                SELECT  GROUP_CONCAT(group_table.group_id)
                                FROM    " . $this->databasePrefix . "user_group group_table
                                WHERE   group_table.user_id = user_table.user_id
                                    AND user_pending = ?
                            ) AS groupIDs
                FROM        " . $this->databasePrefix . "users user_table
                LEFT JOIN   " . $this->databasePrefix . "banlist ban_table
                ON          user_table.user_id = ban_table.ban_userid
                        AND ban_table.ban_end = ?
                LEFT JOIN   " . $this->databasePrefix . "profile_fields_data fields_table
                ON          user_table.user_id = fields_table.user_id
                WHERE       user_table.user_type <> ?
                        AND user_table.user_id BETWEEN ? AND ?
                ORDER BY    user_table.user_id";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([0, 0, self::USER_TYPE_USER_IGNORE, $offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'username' => StringUtil::decodeHTML($row['username']),
                'password' => null,
                'email' => $row['user_email'],
                'registrationDate' => $row['user_regdate'],
                'banned' => $row['banReason'] === null ? 0 : 1,
                'banReason' => $row['banReason'],
                'registrationIpAddress' => UserUtil::convertIPv4To6($row['user_ip']),
                'signature' => self::fixBBCodes(StringUtil::decodeHTML($row['user_sig']), $row['user_sig_bbcode_uid']),
                'lastActivityTime' => $row['user_lastvisit'],
            ];

            $birthday = \DateTime::createFromFormat('j-n-Y', \str_replace(' ', '', $row['user_birthday']));
            // get user options
            $options = [
                'location' => $row['user_from'],
                'birthday' => $birthday ? $birthday->format('Y-m-d') : '',
                'icq' => $row['user_icq'],
                'homepage' => $row['user_website'],
                'hobbies' => $row['user_interests'],
            ];

            $additionalData = [
                'groupIDs' => \explode(',', $row['groupIDs']),
                'languages' => [$row['user_lang']],
                'options' => $options,
            ];

            // handle user options
            foreach ($profileFields as $profileField) {
                if (!empty($row['pf_' . $profileField['field_name']])) {
                    // prevent issues with 0 being false for select
                    // 5 = select
                    if ($profileField['field_type'] == 5) {
                        $additionalData['options'][$profileField['field_id']] = '_' . $row['pf_' . $profileField['field_name']];
                    } else {
                        $additionalData['options'][$profileField['field_id']] = $row['pf_' . $profileField['field_name']];
                    }
                }
            }

            // import user
            $newUserID = ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.user')
                ->import(
                    $row['user_id'],
                    $data,
                    $additionalData
                );

            // update password hash
            if ($newUserID) {
                $passwordUpdateStatement->execute([
                    'phpbb3:' . $row['user_password'] . ':',
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
                FROM    " . $this->databasePrefix . "profile_fields";
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
        $sql = "SELECT      fields.*,
                            (
                                SELECT  GROUP_CONCAT(('_' || lang.option_id || ':' || lang.lang_value) SEPARATOR '\n')
                                FROM    " . $this->databasePrefix . "profile_fields_lang lang
                                WHERE   lang.field_id = fields.field_id
                                    AND lang.field_type = ?
                                    AND lang.lang_id = (
                                            SELECT  MIN(lang_id)
                                            FROM    " . $this->databasePrefix . "profile_fields_lang
                                        )
                            ) AS selectOptions
                FROM        " . $this->databasePrefix . "profile_fields fields
                ORDER BY    fields.field_id";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute([5]);
        while ($row = $statement->fetchArray()) {
            switch ($row['field_type']) {
                case 1:
                    $type = 'integer';
                    break;
                case 2:
                    $type = 'text';
                    break;
                case 3:
                    $type = 'textarea';
                    break;
                case 4:
                    $type = 'boolean';
                    break;
                case 5:
                    $type = 'select';
                    break;
                case 6:
                    $type = 'date';
                    break;
                default:
                    continue 2;
            }

            $data = [
                'categoryName' => 'profile.personal',
                'optionType' => $type,
                'editable' => $row['field_show_profile'] ? UserOption::EDITABILITY_ALL : UserOption::EDITABILITY_ADMINISTRATOR,
                'required' => $row['field_required'] ? 1 : 0,
                'askDuringRegistration' => $row['field_show_on_reg'] ? 1 : 0,
                'selectOptions' => $row['selectOptions'] ?: '',
                'visible' => $row['field_no_view'] ? UserOption::VISIBILITY_ADMINISTRATOR | UserOption::VISIBILITY_OWNER : UserOption::VISIBILITY_ALL,
                'showOrder' => $row['field_order'],
                'outputClass' => $type == 'select' ? SelectOptionsUserOptionOutput::class : '',
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.user.option')
                ->import(
                    $row['field_id'],
                    $data,
                    [
                        'name' => $row['field_name'],
                    ]
                );
        }
    }

    /**
     * Counts user ranks.
     */
    public function countUserRanks()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "ranks
                WHERE   rank_special = ?";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([0]);
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Exports user ranks.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportUserRanks($offset, $limit)
    {
        $sql = "SELECT      *
                FROM        " . $this->databasePrefix . "ranks
                WHERE       rank_special = ?
                ORDER BY    rank_id";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute([0]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'groupID' => 2, // 2 = registered users
                'requiredPoints' => $row['rank_min'] * 5,
                'rankTitle' => $row['rank_title'],
                'rankImage' => $row['rank_image'],
                'repeatImage' => 0,
                'requiredGender' => 0, // neutral
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.user.rank')
                ->import($row['rank_id'], $data);
        }
    }

    /**
     * Counts followers.
     */
    public function countFollowers()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "zebra
                WHERE   friend = ?
                    AND foe = ?";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([1, 0]);
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
                FROM        " . $this->databasePrefix . "zebra
                WHERE       friend = ?
                        AND foe = ?
                ORDER BY    user_id, zebra_id";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute([1, 0]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'userID' => $row['user_id'],
                'followUserID' => $row['zebra_id'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.user.follower')
                ->import(0, $data);
        }
    }

    /**
     * Counts user avatars.
     */
    public function countUserAvatars()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "users
                WHERE   user_avatar_type IN (?, ?)";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([self::AVATAR_TYPE_GALLERY, self::AVATAR_TYPE_UPLOADED]);
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Exports user avatars.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportUserAvatars($offset, $limit)
    {
        static $avatar_salt = null, $avatar_path = null, $avatar_gallery_path = null;
        if ($avatar_salt === null) {
            $sql = "SELECT  config_name, config_value
                    FROM    " . $this->databasePrefix . "config
                    WHERE   config_name IN (?, ?, ?)";
            $statement = $this->database->prepareStatement($sql);
            $statement->execute(['avatar_path', 'avatar_salt', 'avatar_gallery_path']);
            while ($row = $statement->fetchArray()) {
                $config_name = $row['config_name'];
                /** @noinspection PhpVariableVariableInspection */
                ${$config_name} = $row['config_value'];
            }
        }

        $sql = "SELECT      user_id, user_avatar, user_avatar_type, user_avatar_width, user_avatar_height
                FROM        " . $this->databasePrefix . "users
                WHERE       user_avatar_type IN (?, ?)
                ORDER BY    user_id";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute([self::AVATAR_TYPE_GALLERY, self::AVATAR_TYPE_UPLOADED]);
        while ($row = $statement->fetchArray()) {
            $extension = \pathinfo($row['user_avatar'], \PATHINFO_EXTENSION);
            switch ($row['user_avatar_type']) {
                case self::AVATAR_TYPE_UPLOADED:
                    $location = FileUtil::addTrailingSlash($this->fileSystemPath . $avatar_path) . $avatar_salt . '_' . \intval($row['user_avatar']) . '.' . $extension;
                    break;
                case self::AVATAR_TYPE_GALLERY:
                    $location = FileUtil::addTrailingSlash($this->fileSystemPath . $avatar_gallery_path) . $row['user_avatar'];
                    break;
                default:
                    continue 2;
            }

            $data = [
                'avatarName' => \basename($row['user_avatar']),
                'userID' => $row['user_id'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.user.avatar')
                ->import(
                    0,
                    $data,
                    ['fileLocation' => $location]
                );
        }
    }

    /**
     * Counts conversation folders.
     */
    public function countConversationFolders()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "privmsgs_folder";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute();
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Exports conversation folders.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportConversationFolders($offset, $limit)
    {
        $sql = "SELECT      *
                FROM        " . $this->databasePrefix . "privmsgs_folder
                ORDER BY    folder_id";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $data = [
                'userID' => $row['user_id'],
                'label' => \mb_substr($row['folder_name'], 0, 80),
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.conversation.label')
                ->import($row['folder_id'], $data);
        }
    }

    /**
     * Creates a conversation id out of the old rootLevel and the participants.
     *
     * This ensures that only the actual receivers of a pm are able to see it
     * after import, while minimizing the number of conversations.
     *
     * @param   integer     $rootLevel
     * @param   integer[]   $participants
     * @return  string
     */
    private function getConversationID($rootLevel, array $participants)
    {
        $conversationID = $rootLevel;
        $participants = \array_unique($participants);
        \sort($participants);
        $conversationID .= '-' . \implode(',', $participants);

        return \sha1($conversationID);
    }

    /**
     * Counts conversations.
     */
    public function countConversations()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "privmsgs";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute();
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Exports conversations.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportConversations($offset, $limit)
    {
        $sql = "(
                    SELECT      msg_table.msg_id,
                                msg_table.root_level,
                                msg_table.message_subject,
                                msg_table.message_time,
                                msg_table.author_id,
                                0 AS isDraft,
                                user_table.username,
                                (
                                    SELECT  GROUP_CONCAT(to_table.user_id)
                                    FROM    " . $this->databasePrefix . "privmsgs_to to_table
                                    WHERE   msg_table.msg_id = to_table.msg_id
                                ) AS participants
                    FROM        " . $this->databasePrefix . "privmsgs msg_table
                    LEFT JOIN   " . $this->databasePrefix . "users user_table
                    ON          msg_table.author_id = user_table.user_id
                )
                UNION
                (
                    SELECT      draft_table.draft_id AS msg_id,
                                0 AS root_level,
                                draft_table.draft_subject AS message_subject,
                                draft_table.save_time AS message_time,
                                draft_table.user_id AS author_id,
                                1 AS isDraft,
                                user_table.username,
                                '' AS participants
                    FROM        " . $this->databasePrefix . "drafts draft_table
                    LEFT JOIN   " . $this->databasePrefix . "users user_table
                    ON          draft_table.user_id = user_table.user_id
                    WHERE       forum_id = ?
                )
                ORDER BY        isDraft, msg_id";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute([0]);
        while ($row = $statement->fetchArray()) {
            if (!$row['isDraft']) {
                $participants = \explode(',', $row['participants']);
                $participants[] = $row['author_id'];
                $conversationID = $this->getConversationID($row['root_level'] ?: $row['msg_id'], $participants);

                if (ImportHandler::getInstance()->getNewID('com.woltlab.wcf.conversation', $conversationID) !== null) {
                    continue;
                }
            }

            $data = [
                'subject' => StringUtil::decodeHTML($row['message_subject']),
                'time' => $row['message_time'],
                'userID' => $row['author_id'],
                'username' => StringUtil::decodeHTML($row['username']) ?: '',
                'isDraft' => $row['isDraft'],
            ];

            /** @noinspection PhpUndefinedVariableInspection */
            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.conversation')
                ->import(
                    ($row['isDraft'] ? 'draft-' . $row['msg_id'] : $conversationID),
                    $data
                );
        }
    }

    /**
     * Counts conversation messages.
     */
    public function countConversationMessages()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "privmsgs";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute();
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Exports conversation messages.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportConversationMessages($offset, $limit)
    {
        $sql = "(
                    SELECT      msg_table.root_level,
                                msg_table.msg_id,
                                msg_table.author_id,
                                user_table.username,
                                msg_table.message_text,
                                msg_table.bbcode_uid,
                                msg_table.message_time,
                                msg_table.enable_smilies,
                                msg_table.enable_bbcode,
                                msg_table.enable_sig,
                                (
                                    SELECT  COUNT(*)
                                    FROM    " . $this->databasePrefix . "attachments attachment_table
                                    WHERE   attachment_table.post_msg_id = msg_table.msg_id
                                        AND in_message = ?
                                ) AS attachments,
                                (
                                    SELECT  GROUP_CONCAT(to_table.user_id)
                                    FROM    " . $this->databasePrefix . "privmsgs_to to_table
                                    WHERE   msg_table.msg_id = to_table.msg_id
                                ) AS participants
                    FROM        " . $this->databasePrefix . "privmsgs msg_table
                    LEFT JOIN   " . $this->databasePrefix . "users user_table
                    ON          msg_table.author_id = user_table.user_id
                )
                UNION
                (
                    SELECT      0 AS root_level,
                                ('draft-' || draft_table.draft_id) AS msg_id,
                                draft_table.user_id AS author_id,
                                user_table.username,
                                draft_table.draft_message AS message_text,
                                '' AS bbcode_uid,
                                draft_table.save_time AS message_time,
                                1 AS enable_smilies,
                                1 AS enable_bbcode,
                                1 AS enable_sig,
                                0 AS attachments,
                                '' AS participants
                    FROM        " . $this->databasePrefix . "drafts draft_table
                    LEFT JOIN   " . $this->databasePrefix . "users user_table
                    ON          draft_table.user_id = user_table.user_id
                    WHERE       forum_id = ?
                )
                ORDER BY    msg_id";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute([1, 0]);
        while ($row = $statement->fetchArray()) {
            $participants = \explode(',', $row['participants']);
            $participants[] = $row['author_id'];
            $conversationID = $this->getConversationID($row['root_level'] ?: $row['msg_id'], $participants);

            $data = [
                'conversationID' => $conversationID,
                'userID' => $row['author_id'],
                'username' => StringUtil::decodeHTML($row['username']) ?: '',
                'message' => self::fixBBCodes(StringUtil::decodeHTML($row['message_text']), $row['bbcode_uid']),
                'time' => $row['message_time'],
                'attachments' => $row['attachments'],
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
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "privmsgs_to";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute();
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Exports conversation recipients.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportConversationUsers($offset, $limit)
    {
        $sql = "SELECT      to_table.*, msg_table.root_level, msg_table.author_id, msg_table.bcc_address,
                            user_table.username, msg_table.message_time,
                            (
                                SELECT  GROUP_CONCAT(to_table2.user_id)
                                FROM    " . $this->databasePrefix . "privmsgs_to to_table2
                                WHERE   to_table.msg_id = to_table2.msg_id
                            ) AS participants
                FROM        " . $this->databasePrefix . "privmsgs_to to_table
                LEFT JOIN   " . $this->databasePrefix . "privmsgs msg_table
                ON          msg_table.msg_id = to_table.msg_id
                LEFT JOIN   " . $this->databasePrefix . "users user_table
                ON          to_table.user_id = user_table.user_id
                ORDER BY    to_table.msg_id, to_table.user_id";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $participants = \explode(',', $row['participants']);
            $participants[] = $row['author_id'];
            $conversationID = $this->getConversationID($row['root_level'] ?: $row['msg_id'], $participants);

            $bcc = \explode(':', $row['bcc_address']);

            $data = [
                'conversationID' => $conversationID,
                'participantID' => $row['user_id'],
                'username' => StringUtil::decodeHTML($row['username']) ?: '',
                'hideConversation' => $row['pm_deleted'],
                'isInvisible' => \in_array('u_' . $row['user_id'], $bcc) ? 1 : 0,
                'lastVisitTime' => $row['pm_new'] ? 0 : $row['message_time'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.conversation.user')
                ->import(
                    0,
                    $data,
                    [
                        'labelIDs' => ($row['folder_id'] > 0) ? [$row['folder_id']] : [],
                    ]
                );
        }
    }

    /**
     * Counts conversation attachments.
     */
    public function countConversationAttachments()
    {
        return $this->countAttachments(1);
    }

    /**
     * Exports conversation attachments.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportConversationAttachments($offset, $limit)
    {
        return $this->exportAttachments(1, $offset, $limit);
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
                ORDER BY    parent_id, left_id, forum_id";
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
    protected function exportBoardsRecursively($parentID = 0)
    {
        if (!isset($this->boardCache[$parentID])) {
            return;
        }

        foreach ($this->boardCache[$parentID] as $board) {
            $boardType = Board::TYPE_BOARD;
            if ($board['forum_type'] == self::BOARD_TYPE_LINK) {
                $boardType = Board::TYPE_LINK;
            } elseif ($board['forum_type'] == self::BOARD_TYPE_CATEGORY) {
                $boardType = Board::TYPE_CATEGORY;
            }

            $data = [
                'parentID' => $board['parent_id'] ?: null,
                'position' => $board['left_id'],
                'boardType' => $boardType,
                'title' => StringUtil::decodeHTML($board['forum_name']),
                'description' => $board['forum_desc'],
                'descriptionUseHtml' => 1, // cannot be disabled
                'externalURL' => $board['forum_link'],
                'countUserPosts' => 1, // cannot be disabled
                'isClosed' => $board['forum_status'] ? 1 : 0,
                'searchable' => $board['enable_indexing'] ? 1 : 0,
                'threadsPerPage' => $board['forum_topics_per_page'] ?: 0,
                'clicks' => $board['forum_posts'],
                'posts' => $board['forum_posts'],
                'threads' => $board['forum_topics'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.board')
                ->import($board['forum_id'], $data);

            $this->exportBoardsRecursively($board['forum_id']);
        }
    }

    /**
     * Counts threads.
     */
    public function countThreads()
    {
        return $this->__getMaxID($this->databasePrefix . "topics", 'topic_id');
    }

    /**
     * Exports threads.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportThreads($offset, $limit)
    {
        $boardIDs = \array_keys(BoardCache::getInstance()->getBoards());

        $sql = "SELECT      *
                FROM        " . $this->databasePrefix . "topics
                WHERE       topic_id BETWEEN ? AND ?
                ORDER BY    topic_id";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'boardID' => $row['forum_id'] ?: $boardIDs[0], // map global annoucements to a random board
                'topic' => StringUtil::decodeHTML($row['topic_title']),
                'time' => $row['topic_time'],
                'userID' => $row['topic_poster'],
                'username' => $row['topic_first_poster_name'],
                'views' => $row['topic_views'],
                'isAnnouncement' => ($row['topic_type'] == self::TOPIC_TYPE_ANNOUCEMENT || $row['topic_type'] == self::TOPIC_TYPE_GLOBAL) ? 1 : 0,
                'isSticky' => $row['topic_type'] == self::TOPIC_TYPE_STICKY ? 1 : 0,
                'isDisabled' => 0,
                'isClosed' => $row['topic_status'] == self::TOPIC_STATUS_CLOSED ? 1 : 0,
                'movedThreadID' => ($row['topic_status'] == self::TOPIC_STATUS_LINK && $row['topic_moved_id']) ? $row['topic_moved_id'] : null,
                'movedTime' => 0,
            ];

            $additionalData = [];
            if ($row['topic_type'] == self::TOPIC_TYPE_GLOBAL) {
                $additionalData['assignedBoards'] = $boardIDs;
            }
            if ($row['topic_type'] == self::TOPIC_TYPE_ANNOUCEMENT) {
                $additionalData['assignedBoards'] = [$row['forum_id']];
            }

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.thread')
                ->import(
                    $row['topic_id'],
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
        return $this->__getMaxID($this->databasePrefix . "posts", 'post_id');
    }

    /**
     * Exports posts.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportPosts($offset, $limit)
    {
        $sql = "SELECT      post_table.*, user_table.username, editor.username AS editorName,
                            (
                                SELECT  COUNT(*)
                                FROM    " . $this->databasePrefix . "attachments attachment_table
                                WHERE   attachment_table.post_msg_id = post_table.post_id
                                    AND in_message = ?
                            ) AS attachments
                FROM        " . $this->databasePrefix . "posts post_table
                LEFT JOIN   " . $this->databasePrefix . "users user_table
                ON          post_table.poster_id = user_table.user_id
                LEFT JOIN   " . $this->databasePrefix . "users editor
                ON          post_table.post_edit_user = editor.user_id
                WHERE       post_id BETWEEN ? AND ?
                ORDER BY    post_id";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([0, $offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'threadID' => $row['topic_id'],
                'userID' => $row['poster_id'],
                'username' => $row['post_username'] ?: (StringUtil::decodeHTML($row['username']) ?: ''),
                'subject' => StringUtil::decodeHTML($row['post_subject']),
                'message' => self::fixBBCodes(StringUtil::decodeHTML($row['post_text']), $row['bbcode_uid']),
                'time' => $row['post_time'],
                'isDisabled' => $row['post_approved'] ? 0 : 1,
                'isClosed' => $row['post_edit_locked'] ? 1 : 0,
                'editorID' => $row['post_edit_user'] ?: null,
                'editor' => $row['editorName'] ?: '',
                'lastEditTime' => $row['post_edit_time'],
                'editCount' => $row['post_edit_count'],
                'editReason' => !empty($row['post_edit_reason']) ? $row['post_edit_reason'] : '',
                'attachments' => $row['attachments'],
                'enableHtml' => 0,
                'ipAddress' => UserUtil::convertIPv4To6($row['poster_ip']),
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.post')
                ->import($row['post_id'], $data);
        }
    }

    /**
     * Counts post attachments.
     */
    public function countPostAttachments()
    {
        return $this->countAttachments(0);
    }

    /**
     * Exports post attachments.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportPostAttachments($offset, $limit)
    {
        return $this->exportAttachments(0, $offset, $limit);
    }

    /**
     * Counts watched threads.
     */
    public function countWatchedThreads()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "topics_watch";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute();
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
        // TODO: This is untested. I cannot find the button to watch a topic.
        // TODO: Import bookmarks as watched threads as well?
        $sql = "SELECT      *
                FROM        " . $this->databasePrefix . "topics_watch
                ORDER BY    topic_id, user_id";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $data = [
                'objectID' => $row['topic_id'],
                'userID' => $row['user_id'],
                'notification' => $row['notify_status'],
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
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "topics
                WHERE   poll_start <> ?";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([0]);
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Exports polls.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportPolls($offset, $limit)
    {
        $sql = "SELECT      topic_id, topic_first_post_id, poll_title, poll_start,
                            poll_length, poll_max_options, poll_vote_change,
                            (
                                SELECT  COUNT(DISTINCT vote_user_id)
                                FROM    " . $this->databasePrefix . "poll_votes votes
                                WHERE   votes.topic_id = topic.topic_id
                            ) AS poll_votes
                FROM        " . $this->databasePrefix . "topics topic
                WHERE       poll_start <> ?
                ORDER BY    topic_id";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute([0]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'objectID' => $row['topic_first_post_id'],
                'question' => $row['poll_title'],
                'time' => $row['poll_start'],
                'endTime' => $row['poll_length'] ? $row['poll_start'] + $row['poll_length'] : 0,
                'isChangeable' => $row['poll_vote_change'] ? 1 : 0,
                'isPublic' => 0,
                'maxVotes' => $row['poll_max_options'],
                'votes' => $row['poll_votes'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.poll')
                ->import($row['topic_id'], $data);
        }
    }

    /**
     * Counts poll options.
     */
    public function countPollOptions()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "poll_options";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute();
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Exports poll options.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportPollOptions($offset, $limit)
    {
        $sql = "SELECT      *
                FROM        " . $this->databasePrefix . "poll_options
                ORDER BY    poll_option_id";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $data = [
                'pollID' => $row['topic_id'],
                'optionValue' => $row['poll_option_text'],
                'showOrder' => $row['poll_option_id'],
                'votes' => $row['poll_option_total'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.poll.option')
                ->import(
                    ($row['topic_id'] . '-' . $row['poll_option_id']),
                    $data
                );
        }
    }

    /**
     * Counts poll option votes.
     */
    public function countPollOptionVotes()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "poll_votes
                WHERE   vote_user_id <> ?";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([0]);
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Exports poll option votes.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportPollOptionVotes($offset, $limit)
    {
        $sql = "SELECT      *
                FROM        " . $this->databasePrefix . "poll_votes
                WHERE       vote_user_id <> ?
                ORDER BY    poll_option_id, vote_user_id";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute([0]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'pollID' => $row['topic_id'],
                'optionID' => $row['topic_id'] . '-' . $row['poll_option_id'],
                'userID' => $row['vote_user_id'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.poll.option.vote')
                ->import(0, $data);
        }
    }

    /**
     * Counts ACLs.
     */
    public function countACLs()
    {
        $sql = "SELECT  (
                    SELECT  COUNT(*)
                    FROM    " . $this->databasePrefix . "acl_users
                    WHERE   forum_id <> ?
                ) + (
                    SELECT  COUNT(*)
                    FROM    " . $this->databasePrefix . "acl_groups
                    WHERE   forum_id <> ?
                ) AS count";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([0, 0]);
        $row = $statement->fetchArray();

        return $row['count'] ? 2 : 0;
    }

    /**
     * Exports ACLs.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportACLs($offset, $limit)
    {
        $sql = "SELECT  *
                FROM    " . $this->databasePrefix . "acl_options
                WHERE   is_local = ?";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([1]);
        $options = [];
        while ($row = $statement->fetchArray()) {
            $options[$row['auth_option_id']] = $row;
        }

        $condition = new PreparedStatementConditionBuilder();
        $condition->add('auth_option_id IN (?)', [\array_keys($options)]);
        $sql = "SELECT  *
                FROM    " . $this->databasePrefix . "acl_roles_data
                " . $condition;
        $statement = $this->database->prepareStatement($sql);
        $statement->execute($condition->getParameters());
        $roles = [];
        while ($row = $statement->fetchArray()) {
            $roles[$row['role_id']][$row['auth_option_id']] = $row['auth_setting'];
        }

        $data = [];
        $key = '';
        if ($offset == 0) {
            // groups
            $sql = "SELECT      *
                    FROM        " . $this->databasePrefix . "acl_groups
                    WHERE       forum_id <> ?
                    ORDER BY    auth_role_id DESC";
            $statement = $this->database->prepareStatement($sql);
            $statement->execute([0]);
            $key = 'group';
        } elseif ($offset == 1) {
            // users
            $sql = "SELECT      *
                    FROM        " . $this->databasePrefix . "acl_users
                    WHERE       forum_id <> ?
                    ORDER BY    auth_role_id DESC";
            $statement = $this->database->prepareStatement($sql);
            $statement->execute([0]);
            $key = 'user';
        }

        while ($row = $statement->fetchArray()) {
            if ($row['auth_role_id'] != 0) {
                if (!isset($roles[$row['auth_role_id']])) {
                    continue;
                }

                foreach ($roles[$row['auth_role_id']] as $optionID => $setting) {
                    if (!isset($options[$optionID])) {
                        continue;
                    }

                    $current = 1;
                    if (isset($groups[$row[$key . '_id']][$row['forum_id']][$optionID])) {
                        $current = $data[$row[$key . '_id']][$row['forum_id']][$optionID];
                    }

                    // a setting of zero means never -> use minimum
                    $data[$row[$key . '_id']][$row['forum_id']][$optionID] = \min($current, $setting);
                }
            } else {
                if (!isset($options[$row['auth_option_id']])) {
                    continue;
                }

                $current = 1;
                if (isset($groups[$row[$key . '_id']][$row['forum_id']][$row['auth_option_id']])) {
                    $current = $data[$row[$key . '_id']][$row['forum_id']][$row['auth_option_id']];
                }

                // a setting of zero means never -> use minimum
                $data[$row[$key . '_id']][$row['forum_id']][$row['auth_option_id']] = \min($current, $row['auth_setting']);
            }
        }

        static $optionMapping = [
            'f_announce' => ['canStartAnnouncement'],
            'f_attach' => ['canUploadAttachment'],
            'f_bbcode' => [],
            'f_bump' => [],
            'f_delete' => ['canDeleteOwnPost'],
            'f_download' => ['canDownloadAttachment', 'canViewAttachmentPreview'],
            'f_edit' => ['canEditOwnPost'],
            'f_email' => [],
            'f_flash' => [],
            'f_icons' => [],
            'f_ignoreflood' => [],
            'f_img' => [],
            'f_list' => ['canViewBoard'],
            'f_noapprove' => ['canStartThreadWithoutModeration', 'canReplyThreadWithoutModeration'],
            'f_poll' => ['canStartPoll'],
            'f_post' => ['canStartThread'],
            'f_postcount' => [],
            'f_print' => [],
            'f_read' => ['canEnterBoard'],
            'f_reply' => ['canReplyThread'],
            'f_report' => [],
            'f_search' => [],
            'f_sigs' => [],
            'f_smilies' => [],
            'f_sticky' => ['canPinThread'],
            'f_subscribe' => [],
            'f_user_lock' => [],
            'f_vote' => ['canVotePoll'],
            'f_votechg' => [],
            'm_approve' => ['canEnableThread'],
            'm_chgposter' => [],
            'm_delete' => [
                'canDeleteThread', 'canReadDeletedThread', 'canRestoreThread', 'canDeleteThreadCompletely',
                'canDeletePost', 'canReadDeletedPost', 'canRestorePost', 'canDeletePostCompletely',
            ],
            'm_edit' => ['canEditPost'],
            'm_info' => [],
            'm_lock' => ['canCloseThread', 'canReplyClosedThread'],
            'm_merge' => ['canMergeThread', 'canMergePost'],
            'm_move' => ['canMoveThread', 'canMovePost'],
            'm_report' => [],
            'm_split' => [],
        ];

        foreach ($data as $id => $forumData) {
            foreach ($forumData as $forumID => $settingData) {
                foreach ($settingData as $optionID => $value) {
                    if (!isset($optionMapping[$options[$optionID]['auth_option']])) {
                        continue;
                    }
                    foreach ($optionMapping[$options[$optionID]['auth_option']] as $optionName) {
                        ImportHandler::getInstance()->getImporter('com.woltlab.wbb.acl')->import(0, [
                            'objectID' => $forumID,
                            $key . 'ID' => $id,
                            'optionValue' => $value,
                        ], [
                            'optionName' => $optionName,
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Counts smilies.
     */
    public function countSmilies()
    {
        $sql = "SELECT  COUNT(DISTINCT smiley_url) AS count
                FROM    " . $this->databasePrefix . "smilies";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute();
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Exports smilies.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportSmilies($offset, $limit)
    {
        $sql = "SELECT      MIN(smiley_id) AS smiley_id,
                            GROUP_CONCAT(code SEPARATOR '\n') AS aliases,
                            smiley_url,
                            MIN(smiley_order) AS smiley_order,
                            GROUP_CONCAT(emotion SEPARATOR '\n') AS emotion
                FROM        " . $this->databasePrefix . "smilies
                GROUP BY    smiley_url
                ORDER BY    smiley_id";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute([]);
        while ($row = $statement->fetchArray()) {
            $fileLocation = $this->fileSystemPath . 'images/smilies/' . $row['smiley_url'];

            $aliases = \explode("\n", $row['aliases']);
            $code = \array_shift($aliases);
            // we had to GROUP_CONCAT it because of SQL strict mode
            $emotion = \mb_substr(
                $row['emotion'],
                0,
                \mb_strpos($row['emotion'], "\n") ?: \mb_strlen($row['emotion'])
            );

            $data = [
                'smileyTitle' => $emotion,
                'smileyCode' => $code,
                'showOrder' => $row['smiley_order'],
                'aliases' => \implode("\n", $aliases),
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.smiley')
                ->import(
                    $row['smiley_id'],
                    $data,
                    ['fileLocation' => $fileLocation]
                );
        }
    }

    /**
     * Returns the number of atatchments.
     *
     * @param   integer     $conversation
     * @return  integer
     */
    protected function countAttachments($conversation)
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "attachments
                WHERE   in_message = ?";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$conversation ? 1 : 0]);
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Exports attachments.
     *
     * @param   integer     $conversation
     * @param   integer     $offset
     * @param   integer     $limit
     */
    protected function exportAttachments($conversation, $offset, $limit)
    {
        static $upload_path = null;
        if ($upload_path === null) {
            $sql = "SELECT  config_name, config_value
                    FROM    " . $this->databasePrefix . "config
                    WHERE   config_name IN (?)";
            $statement = $this->database->prepareStatement($sql);
            $statement->execute(['upload_path']);
            while ($row = $statement->fetchArray()) {
                $config_name = $row['config_name'];
                /** @noinspection PhpVariableVariableInspection */
                ${$config_name} = $row['config_value'];
            }
        }

        $sql = "SELECT      *
                FROM        " . $this->databasePrefix . "attachments
                WHERE       in_message = ?
                ORDER BY    attach_id DESC";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute([$conversation ? 1 : 0]);
        while ($row = $statement->fetchArray()) {
            $fileLocation = FileUtil::addTrailingSlash($this->fileSystemPath . $upload_path) . $row['physical_filename'];

            // TODO: support inline attachments
            $data = [
                'objectID' => $row['post_msg_id'],
                'userID' => $row['poster_id'] ?: null,
                'filename' => $row['real_filename'],
                'downloads' => $row['download_count'],
                'uploadTime' => $row['filetime'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.' . ($conversation ? 'wcf.conversation' : 'wbb') . '.attachment')
                ->import(
                    0,
                    $data,
                    ['fileLocation' => $fileLocation]
                );
        }
    }

    /**
     * Returns message with fixed BBCodes as used in WCF.
     *
     * @param   string      $text
     * @param   string      $uid
     * @return  string
     */
    protected static function fixBBCodes($text, $uid)
    {
        // fix closing list tags
        $text = \preg_replace('~\[/list:(u|o)~i', '[/list', $text);
        // fix closing list element tags
        $text = \preg_replace('~\[/\*:m:' . $uid . '\]~i', '', $text);

        // remove uid
        $text = \preg_replace('~\[(/?[^:\]]+):' . $uid . '~', '[$1', $text);
        $text = \preg_replace('~:' . $uid . '\]~', ']', $text);

        // fix size bbcode
        $text = \preg_replace_callback(
            '~(?<=\[size=)\d+(?=\])~',
            static function ($matches) {
                $wbbSize = 24;
                if ($matches[0] <= 50) {
                    $wbbSize = 8;
                } elseif ($matches[0] <= 85) {
                    $wbbSize = 10;
                } elseif ($matches[0] <= 150) {
                    $wbbSize = 14;
                } elseif ($matches[0] <= 200) {
                    $wbbSize = 18;
                }

                return $wbbSize;
            },
            $text
        );

        // see: https://github.com/phpbb/phpbb3/blob/179f41475b555d0a3314d779d0d7423f66f0fb95/phpBB/includes/functions.php#L3767
        $text = \preg_replace(
            '#<!\-\- s(.*?) \-\-><img src=".*? \/><!\-\- s\1 \-\->#',
            '\\1',
            $text
        );
        $text = \preg_replace(
            '#<!\-\- e \-\-><a href="mailto:(.*?)">.*?</a><!\-\- e \-\->#',
            '[email]\\1[/email]',
            $text
        );
        $text = \preg_replace(
            '#<!\-\- ([mw]) \-\-><a (?:class="[\w\-]+" )?href="(.*?)">.*?</a><!\-\- \1 \-\->#',
            '[url]\\2[/url]',
            $text
        );
        $text = \preg_replace(
            '#<!\-\- l \-\-><a (?:class="[\w\-]+" )?href="(.*?)(?:(&amp;|\?)sid=[0-9a-f]{32})?">.*?</a><!\-\- l \-\->#',
            '[url]\\1[/url]',
            $text
        );

        // fix code php bbcode...
        $text = \preg_replace_callback(
            '#\[code(=php)?\](.*)\[/code\]#s',
            static function ($matches) {
                $content = $matches[2];
                $content = \str_replace([
                    '<br />',
                    '&nbsp;&nbsp;&nbsp;&nbsp;',
                ], [
                    "\n",
                    "\t",
                ], $content);
                $content = \preg_replace('#(?:<span class="syntax[^"]*">|</span>)#', '', $content);

                return '[code' . $matches[1] . ']' . $content . '[/code]';
            },
            $text
        );

        // fix quotes
        $text = \preg_replace_callback('~\[quote="([^"]+?)"\]~', static function ($matches) {
            $username = \str_replace(["\\", "'"], ["\\\\", "\\'"], $matches[1]);

            return "[quote='" . $username . "']";
        }, $text);

        // convert attachments
        // TODO: not supported right now
        $text = \preg_replace('~\[attachment=(\d+)\]<!-- ia\\1 -->.*?<!-- ia\\1 -->\[/attachment\]~', '', $text);

        // remove crap
        $text = MessageUtil::stripCrap($text);

        return $text;
    }
}
