<?php

namespace wcf\system\exporter;

use wbb\data\board\Board;
use wcf\data\user\group\UserGroup;
use wcf\data\user\option\UserOption;
use wcf\system\database\util\PreparedStatementConditionBuilder;
use wcf\system\importer\ImportHandler;
use wcf\system\option\user\SelectOptionsUserOptionOutput;
use wcf\system\Regex;
use wcf\system\request\LinkHandler;
use wcf\system\WCF;
use wcf\util\ArrayUtil;
use wcf\util\FileUtil;
use wcf\util\MessageUtil;
use wcf\util\StringUtil;
use wcf\util\UserRegistrationUtil;
use wcf\util\UserUtil;

/**
 * Exporter for SMF 2.x
 *
 * @author  Tim Duesterhus
 * @copyright   2001-2019 WoltLab GmbH
 * @license GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package WoltLabSuite\Core\System\Exporter
 */
final class SMF2xExporter extends AbstractExporter
{
    const GROUP_EVERYONE = -2;

    const GROUP_GUEST = -1;

    // GROUP_USER needs a fake group id, due to 0 being falsy
    const GROUP_USER = 0;

    const GROUP_USER_FAKE = -3;

    const GROUP_ADMIN = 1;

    const GROUP_MODERATORS = 3;

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
        'com.woltlab.wcf.user.follower' => 100,
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

        if (\version_compare($this->readOption('smfVersion'), '2.0.0', '<')) {
            throw new \RuntimeException('Cannot import less than SMF 2.x.');
        }
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
            if (empty($this->fileSystemPath) || !@\file_exists($this->fileSystemPath . 'SSI.php')) {
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
        return 'smf_';
    }

    /**
     * Counts user groups.
     */
    public function countUserGroups()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "membergroups";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute();
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Exports user groups.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportUserGroups($offset, $limit)
    {
        // import everyone, guests and users pseudogroups
        ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.group')->import(self::GROUP_EVERYONE, [
            'groupName' => 'Everyone',
            'groupType' => UserGroup::EVERYONE,
        ]);
        ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.group')->import(self::GROUP_GUEST, [
            'groupName' => 'Guests',
            'groupType' => UserGroup::GUESTS,
        ]);
        ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.group')->import(self::GROUP_USER_FAKE, [
            'groupName' => 'Users',
            'groupType' => UserGroup::USERS,
        ]);

        $sql = "SELECT      *
                FROM        " . $this->databasePrefix . "membergroups
                ORDER BY    id_group";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $data = [
                'groupName' => $row['group_name'],
                'groupType' => UserGroup::OTHER,
                'userOnlineMarking' => '<span style="color: ' . $row['online_color'] . ';">%s</span>',
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.user.group')
                ->import($row['id_group'], $data);
        }
    }

    /**
     * Counts users.
     */
    public function countUsers()
    {
        return $this->__getMaxID($this->databasePrefix . "members", 'id_member');
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
        $sql = "SELECT  col_name, id_field
                FROM    " . $this->databasePrefix . "custom_fields";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $profileFields[$row['col_name']] = $row;
        }

        // prepare password update
        $sql = "UPDATE  wcf" . WCF_N . "_user
                SET     password = ?
                WHERE   userID = ?";
        $passwordUpdateStatement = WCF::getDB()->prepareStatement($sql);

        // get userIDs
        $userIDs = [];
        $sql = "SELECT      id_member
                FROM        " . $this->databasePrefix . "members
                WHERE       id_member BETWEEN ? AND ?
                ORDER BY    id_member";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $userIDs[] = $row['id_member'];
        }

        // wtf?!
        if (empty($userIDs)) {
            return;
        }

        // get profile field values
        $profileFieldValues = [];
        if (!empty($profileFields)) {
            $condition = new PreparedStatementConditionBuilder();
            $condition->add('id_member IN(?)', [$userIDs]);
            $condition->add('variable IN(?)', [\array_keys($profileFields)]);
            $sql = "SELECT  *
                    FROM    " . $this->databasePrefix . "themes
                    " . $condition;
            $statement = $this->database->prepareStatement($sql);
            $statement->execute($condition->getParameters());
            while ($row = $statement->fetchArray()) {
                if (!isset($profileFieldValues[$row['id_member']])) {
                    $profileFieldValues[$row['id_member']] = [];
                }
                $profileFieldValues[$row['id_member']][$profileFields[$row['variable']]['id_field']] = $row['value'];
            }
        }

        // get users
        $condition = new PreparedStatementConditionBuilder();
        $condition->add('member.id_member IN(?)', [$userIDs]);
        $sql = "SELECT      member.*,
                            ban_group.ban_time, ban_group.expire_time AS banExpire, ban_group.reason AS banReason,
                            (
                                SELECT  COUNT(*)
                                FROM    " . $this->databasePrefix . "moderators moderator
                                WHERE   member.id_member = moderator.id_member
                            ) AS isMod
                FROM        " . $this->databasePrefix . "members member
                LEFT JOIN   " . $this->databasePrefix . "ban_items ban_item
                ON          member.id_member = ban_item.id_member
                LEFT JOIN   " . $this->databasePrefix . "ban_groups ban_group
                ON          ban_item.id_ban_group = ban_group.id_ban_group
                " . $condition;
        $statement = $this->database->prepareStatement($sql);
        $statement->execute($condition->getParameters());
        while ($row = $statement->fetchArray()) {
            $data = [
                'username' => $row['member_name'],
                'password' => null,
                'email' => $row['email_address'],
                'registrationDate' => $row['date_registered'],
                // only permabans are imported
                'banned' => ($row['ban_time'] && $row['banExpire'] === null) ? 1 : 0,
                'banReason' => $row['banReason'],
                // smf's codes are strings
                'activationCode' => $row['validation_code'] ? UserRegistrationUtil::getActivationCode() : 0,
                'registrationIpAddress' => $row['member_ip'], // member_ip2 is HTTP_X_FORWARDED_FOR
                'signature' => $row['signature'],
                'userTitle' => StringUtil::decodeHTML($row['usertitle']),
                'lastActivityTime' => $row['last_login'],
            ];

            // get user options
            $options = [
                'location' => $row['location'],
                'birthday' => $row['birthdate'],
                'icq' => $row['icq'],
                'homepage' => $row['website_url'],
                'aboutMe' => $row['personal_text'],
            ];

            $additionalData = [
                'groupIDs' => \explode(',', $row['additional_groups'] . ',' . $row['id_group']),
                'options' => $options,
            ];

            if ($row['isMod']) {
                $additionalData['groupIDs'][] = self::GROUP_MODERATORS;
            }

            // handle user options
            if (isset($profileFieldValues[$row['id_member']])) {
                foreach ($profileFieldValues[$row['id_member']] as $key => $val) {
                    if (!$val) {
                        continue;
                    }
                    $additionalData['options'][$key] = $val;
                }
            }

            // import user
            $newUserID = ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.user')
                ->import(
                    $row['id_member'],
                    $data,
                    $additionalData
                );

            // update password hash
            if ($newUserID) {
                // The lowered username is used for the salt. The column `passwd_salt` is in fact used
                // for the auto login.
                $passwordUpdateStatement->execute([
                    'smf2:' . $row['passwd'] . ':' . \mb_strtolower($row['member_name']),
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
                FROM    " . $this->databasePrefix . "custom_fields";
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
                FROM        " . $this->databasePrefix . "custom_fields
                ORDER BY    id_field";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            switch ($row['field_type']) {
                case 'text':
                case 'textarea':
                case 'select':
                    // fine
                    break;
                case 'radio':
                    $row['field_type'] = 'radioButton';
                    break;
                case 'check':
                    $row['field_type'] = 'boolean';
                    break;
                default:
                    continue 2;
            }

            $editable = $visible = 0;
            switch ($row['private']) {
                case 0:
                    $visible = UserOption::VISIBILITY_ALL;
                    $editable = UserOption::EDITABILITY_ALL;
                    break;
                case 1:
                    $visible = UserOption::VISIBILITY_ALL;
                    $editable = UserOption::EDITABILITY_ADMINISTRATOR;
                    break;
                case 2:
                    $visible = UserOption::VISIBILITY_ADMINISTRATOR | UserOption::VISIBILITY_OWNER;
                    $editable = UserOption::EDITABILITY_ALL;
                    break;
                case 3:
                    $visible = UserOption::VISIBILITY_ADMINISTRATOR;
                    $editable = UserOption::EDITABILITY_ADMINISTRATOR;
            }

            $data = [
                'categoryName' => 'profile.personal',
                'optionType' => $row['field_type'],
                'editable' => $editable,
                'askDuringRegistration' => $row['show_reg'] ? 1 : 0,
                'selectOptions' => \implode("\n", \explode(',', $row['field_options'])),
                'visible' => $visible,
                'searchable' => $row['can_search'] ? 1 : 0,
                'outputClass' => $row['field_type'] == 'select' ? SelectOptionsUserOptionOutput::class : '',
                'defaultValue' => $row['default_value'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.user.option')
                ->import(
                    $row['id_field'],
                    $data,
                    ['name' => $row['field_name']]
                );
        }
    }

    /**
     * Counts user ranks.
     */
    public function countUserRanks()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "membergroups
                WHERE   min_posts <> ?
                    AND stars <> ?";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([-1, '']);
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
                FROM        " . $this->databasePrefix . "membergroups
                WHERE       min_posts <> ?
                        AND stars <> ?
                ORDER BY    id_group";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute([-1, '']);
        while ($row = $statement->fetchArray()) {
            [$repeatImage, $rankImage] = \explode('#', $row['stars'], 2);

            $groupID = $row['id_group'] == self::GROUP_USER ? self::GROUP_USER_FAKE : $row['id_group'];

            $data = [
                'groupID' => $groupID,
                'requiredPoints' => $row['min_posts'] * 5,
                'rankTitle' => $row['group_name'],
                'rankImage' => $rankImage,
                'repeatImage' => $repeatImage,
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.user.rank')
                ->import($groupID, $data);
        }
    }

    /**
     * Counts followers.
     */
    public function countFollowers()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "members
                WHERE   buddy_list <> ?";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute(['']);
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
        $sql = "SELECT      id_member, buddy_list
                FROM        " . $this->databasePrefix . "members
                WHERE       buddy_list <> ?
                ORDER BY    id_member";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute(['']);
        while ($row = $statement->fetchArray()) {
            $buddylist = \array_unique(ArrayUtil::toIntegerArray(\explode(',', $row['buddy_list'])));

            foreach ($buddylist as $buddy) {
                $data = [
                    'userID' => $row['id_member'],
                    'followUserID' => $buddy,
                ];

                ImportHandler::getInstance()
                    ->getImporter('com.woltlab.wcf.user.follower')
                    ->import(0, $data);
            }
        }
    }

    /**
     * Counts user avatars.
     */
    public function countUserAvatars()
    {
        $sql = "SELECT  (
                    SELECT  COUNT(*) AS count
                    FROM    " . $this->databasePrefix . "attachments
                    WHERE   id_member <> ?
                ) + (
                    SELECT  COUNT(*) AS count
                    FROM    " . $this->databasePrefix . "members
                    WHERE   avatar <> ?
                ) AS count";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute(['', 0]);
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
        $sql = "(
                    SELECT  id_member,
                            'attachment' AS type,
                            filename AS avatarName,
                            (id_attach || '_' || file_hash) AS filename,
                            id_attach,
                            file_hash,
                            id_folder
                    FROM    " . $this->databasePrefix . "attachments
                    WHERE   id_member <> ?
                )
                UNION
                (
                    SELECT  id_member,
                            'user' AS type,
                            avatar AS avatarName,
                            avatar AS filename,
                            '' AS id_attach,
                            '' AS file_hash,
                            '' AS id_folder
                    FROM    " . $this->databasePrefix . "members
                    WHERE   avatar <> ?
                )";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute(['', 0]);

        while ($row = $statement->fetchArray()) {
            switch ($row['type']) {
                case 'attachment':
                    $fileLocation = $this->getAttachmentFilename(
                        $row['id_attach'],
                        $row['id_folder'],
                        $row['file_hash'],
                        $row['filename']
                    );
                    break;
                case 'user':
                    if (FileUtil::isURL($row['filename'])) {
                        return;
                    }
                    $fileLocation = $this->readOption('avatar_directory') . '/' . $row['filename'];
                    break;
            }

            $data = [
                'avatarName' => \basename($row['avatarName']),
                'avatarExtension' => \pathinfo($row['avatarName'], \PATHINFO_EXTENSION),
                'userID' => $row['id_member'],
            ];

            /** @noinspection PhpUndefinedVariableInspection */
            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.user.avatar')
                ->import(
                    0,
                    $data,
                    ['fileLocation' => $fileLocation]
                );
        }
    }

    /**
     * Counts conversation folders.
     */
    public function countConversationFolders()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "members
                WHERE   message_labels <> ?";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute(['']);
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
        $sql = "SELECT      id_member, message_labels
                FROM        " . $this->databasePrefix . "members
                WHERE       message_labels <> ?
                ORDER BY    id_member";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute(['']);
        while ($row = $statement->fetchArray()) {
            $labels = ArrayUtil::trim(\explode(',', $row['message_labels']), false);

            $i = 0;
            foreach ($labels as $label) {
                $data = [
                    'userID' => $row['id_member'],
                    'label' => \mb_substr($label, 0, 80),
                ];

                ImportHandler::getInstance()
                    ->getImporter('com.woltlab.wcf.conversation.label')
                    ->import(
                        ($row['id_member'] . '-' . ($i++)),
                        $data
                    );
            }
        }
    }

    /**
     * Creates a conversation id out of the old pmHead and the participants.
     *
     * This ensures that only the actual receivers of a pm are able to see it
     * after import, while minimizing the number of conversations.
     *
     * @param   integer     $pmHead
     * @param   integer[]   $participants
     * @return  string
     */
    private function getConversationID($pmHead, array $participants)
    {
        $conversationID = $pmHead;
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
        return $this->__getMaxID($this->databasePrefix . "personal_messages", 'id_pm');
    }

    /**
     * Exports conversations.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportConversations($offset, $limit)
    {
        $sql = "SELECT      pm.*,
                            (
                                SELECT  GROUP_CONCAT(recipients.id_member)
                                FROM    " . $this->databasePrefix . "pm_recipients recipients
                                WHERE   pm.id_pm = recipients.id_pm
                            ) AS participants
                FROM        " . $this->databasePrefix . "personal_messages pm
                WHERE       pm.id_pm BETWEEN ? AND ?
                ORDER BY    pm.id_pm";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $participants = \explode(',', $row['participants']);
            $participants[] = $row['id_member_from'];
            $conversationID = $this->getConversationID($row['id_pm_head'], $participants);

            if (ImportHandler::getInstance()->getNewID('com.woltlab.wcf.conversation', $conversationID) !== null) {
                continue;
            }

            $data = [
                'subject' => $row['subject'],
                'time' => $row['msgtime'],
                'userID' => $row['id_member_from'],
                'username' => $row['from_name'],
                'isDraft' => 0,
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.conversation')
                ->import($conversationID, $data);
        }
    }

    /**
     * Counts conversation messages.
     */
    public function countConversationMessages()
    {
        return $this->__getMaxID($this->databasePrefix . "personal_messages", 'id_pm');
    }

    /**
     * Exports conversation messages.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportConversationMessages($offset, $limit)
    {
        $sql = "SELECT      pm.*,
                            (
                                SELECT  GROUP_CONCAT(recipients.id_member)
                                FROM    " . $this->databasePrefix . "pm_recipients recipients
                                WHERE   pm.id_pm = recipients.id_pm
                            ) AS participants
                FROM        " . $this->databasePrefix . "personal_messages pm
                WHERE       pm.id_pm BETWEEN ? AND ?
                ORDER BY    pm.id_pm";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $participants = \explode(',', $row['participants']);
            $participants[] = $row['id_member_from'];
            $conversationID = $this->getConversationID($row['id_pm_head'], $participants);

            $data = [
                'conversationID' => $conversationID,
                'userID' => $row['id_member_from'],
                'username' => $row['from_name'],
                'message' => self::fixBBCodes($row['body']),
                'time' => $row['msgtime'],
                'attachments' => 0, // not supported
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.conversation.message')
                ->import($row['id_pm'], $data);
        }
    }

    /**
     * Counts conversation recipients.
     */
    public function countConversationUsers()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "pm_recipients";
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
        $sql = "SELECT      recipients.*, pm.id_pm_head, members.member_name, pm.msgtime, pm.id_member_from,
                            (
                                SELECT  GROUP_CONCAT(recipients2.id_member)
                                FROM    " . $this->databasePrefix . "pm_recipients recipients2
                                WHERE   recipients.id_pm = recipients2.id_pm
                            ) AS participants
                FROM        " . $this->databasePrefix . "pm_recipients recipients
                LEFT JOIN   " . $this->databasePrefix . "personal_messages pm
                ON          pm.id_pm = recipients.id_pm
                LEFT JOIN   " . $this->databasePrefix . "members members
                ON          recipients.id_member = members.id_member
                ORDER BY    recipients.id_pm, recipients.id_member";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $participants = \explode(',', $row['participants']);
            $participants[] = $row['id_member_from'];
            $conversationID = $this->getConversationID($row['id_pm_head'], $participants);

            $labels = \array_map(static function ($item) use ($row) {
                return $row['id_member'] . '-' . $item;
            }, \array_unique(ArrayUtil::toIntegerArray(\explode(',', $row['labels']))));
            $labels = \array_filter($labels, static function ($item) {
                return $item != '-1';
            });

            $data = [
                'conversationID' => $conversationID,
                'participantID' => $row['id_member'],
                'username' => ($row['member_name'] ?: ''),
                'hideConversation' => $row['deleted'] ? 1 : 0,
                'isInvisible' => $row['bcc'] ? 1 : 0,
                'lastVisitTime' => $row['is_new'] ? 0 : $row['msgtime'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.conversation.user')
                ->import(
                    0,
                    $data,
                    ['labelIDs' => $labels]
                );
        }
    }

    /**
     * Counts boards.
     */
    public function countBoards()
    {
        $sql = "SELECT  (
                    SELECT  COUNT(*)
                    FROM    " . $this->databasePrefix . "boards
                ) + (
                    SELECT  COUNT(*)
                    FROM    " . $this->databasePrefix . "categories
                ) AS count";
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
                FROM        " . $this->databasePrefix . "categories
                ORDER BY    id_cat";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $data = [
                'parentID' => null,
                'position' => $row['cat_order'],
                'boardType' => Board::TYPE_CATEGORY,
                'title' => $row['name'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.board')
                ->import('cat-' . $row['id_cat'], $data);
        }

        $sql = "SELECT      *
                FROM        " . $this->databasePrefix . "boards
                ORDER BY    id_board";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $this->boardCache[$row['id_parent']][] = $row;
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
            $data = [
                'parentID' => $board['id_parent'] ?: 'cat-' . $board['id_cat'],
                'position' => $board['board_order'],
                'boardType' => $board['redirect'] ? Board::TYPE_LINK : Board::TYPE_BOARD,
                'title' => \str_replace('&amp;', '&', $board['name']),
                'description' => $board['description'],
                'descriptionUseHtml' => 1,
                'externalURL' => $board['redirect'],
                'countUserPosts' => $board['count_posts'] ? 0 : 1, // this column name is SLIGHTLY misleading
                'clicks' => $board['num_posts'],
                'posts' => $board['num_posts'],
                'threads' => $board['num_topics'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.board')
                ->import($board['id_board'], $data);

            $this->exportBoardsRecursively($board['id_board']);
        }
    }

    /**
     * Counts threads.
     */
    public function countThreads()
    {
        return $this->__getMaxID($this->databasePrefix . "topics", 'id_topic');
    }

    /**
     * Exports threads.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportThreads($offset, $limit)
    {
        // get threads
        $sql = "SELECT      topic.*, post.subject, post.poster_time AS time, post.poster_name AS username
                FROM        " . $this->databasePrefix . "topics topic
                LEFT JOIN   " . $this->databasePrefix . "messages post
                ON          post.id_msg = topic.id_first_msg
                WHERE       topic.id_topic BETWEEN ? AND ?
                ORDER BY    topic.id_topic";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'boardID' => $row['id_board'],
                'topic' => StringUtil::decodeHTML($row['subject']),
                'time' => $row['time'],
                'userID' => $row['id_member_started'],
                'username' => $row['username'],
                'views' => $row['num_views'],
                'isAnnouncement' => 0,
                'isSticky' => $row['is_sticky'] ? 1 : 0,
                'isDisabled' => $row['approved'] ? 0 : 1,
                'isClosed' => $row['locked'] ? 1 : 0,
                'movedThreadID' => null,
                'movedTime' => 0,
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.thread')
                ->import($row['id_topic'], $data);
        }
    }

    /**
     * Counts posts.
     */
    public function countPosts()
    {
        return $this->__getMaxID($this->databasePrefix . "messages", 'id_msg');
    }

    /**
     * Exports posts.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportPosts($offset, $limit)
    {
        $sql = "SELECT      message.*, member.id_member AS editorID
                FROM        " . $this->databasePrefix . "messages message
                LEFT JOIN   " . $this->databasePrefix . "members member
                ON          message.modified_name = member.real_name
                WHERE       message.id_msg BETWEEN ? AND ?
                ORDER BY    message.id_msg";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'threadID' => $row['id_topic'],
                'userID' => $row['id_member'],
                'username' => $row['poster_name'],
                'subject' => StringUtil::decodeHTML($row['subject']),
                'message' => self::fixBBCodes($row['body']),
                'time' => $row['poster_time'],
                'isDisabled' => $row['approved'] ? 0 : 1,
                'editorID' => $row['editorID'] ?: null,
                'editor' => $row['modified_name'],
                'lastEditTime' => $row['modified_time'],
                'editCount' => $row['modified_time'] ? 1 : 0,
                'editReason' => !empty($row['editReason']) ? $row['editReason'] : '',
                'enableHtml' => 0,
                'ipAddress' => UserUtil::convertIPv4To6($row['poster_ip']),
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.post')
                ->import($row['id_msg'], $data);
        }
    }

    /**
     * Counts post attachments.
     */
    public function countPostAttachments()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "attachments
                WHERE   id_member = ?
                    AND id_msg <> ?";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([0, 0]);
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Exports post attachments.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportPostAttachments($offset, $limit)
    {
        $sql = "SELECT      attachment.*, message.id_member, message.poster_time
                FROM        " . $this->databasePrefix . "attachments attachment
                INNER JOIN  " . $this->databasePrefix . "messages message
                ON          message.id_msg = attachment.id_msg
                WHERE       attachment.id_member = ?
                        AND attachment.id_msg <> ?
                ORDER BY    attachment.id_attach";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute([0, 0]);
        while ($row = $statement->fetchArray()) {
            if (\substr($row['filename'], -6) == '_thumb') {
                continue; // ignore thumbnails
            }

            $fileLocation = $this->getAttachmentFilename(
                $row['id_attach'],
                $row['id_folder'],
                $row['file_hash'],
                $row['filename']
            );

            $data = [
                'objectID' => $row['id_msg'],
                'userID' => $row['id_member'] ?: null,
                'filename' => $row['filename'],
                'downloads' => $row['downloads'],
                'uploadTime' => $row['poster_time'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.attachment')
                ->import(
                    $row['id_attach'],
                    $data,
                    ['fileLocation' => $fileLocation]
                );
        }
    }

    /**
     * Counts watched threads.
     */
    public function countWatchedThreads()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "log_notify
                WHERE   id_topic <> ?
                    AND id_board = ?";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([0, 0]);
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
                FROM        " . $this->databasePrefix . "log_notify
                WHERE       id_topic <> ?
                        AND id_board = ?
                ORDER BY    id_member, id_topic";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute([0, 0]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'objectID' => $row['id_topic'],
                'userID' => $row['id_member'],
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
                FROM    " . $this->databasePrefix . "polls";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute();
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
        $sql = "SELECT      poll.*, topic.id_first_msg,
                            (
                                SELECT  COUNT(DISTINCT id_member)
                                FROM    " . $this->databasePrefix . "log_polls vote
                                WHERE   poll.id_poll = vote.id_poll
                            ) AS votes
                FROM        " . $this->databasePrefix . "polls poll
                INNER JOIN  " . $this->databasePrefix . "topics topic
                ON          topic.id_poll = poll.id_poll
                ORDER BY    id_poll";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $data = [
                'objectID' => $row['id_first_msg'],
                'question' => $row['question'],
                'endTime' => $row['expire_time'],
                'isChangeable' => $row['change_vote'] ? 1 : 0,
                'isPublic' => $row['hide_results'] ? 0 : 1,
                'maxVotes' => $row['max_votes'],
                'votes' => $row['votes'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.poll')
                ->import($row['id_poll'], $data);
        }
    }

    /**
     * Counts poll options.
     */
    public function countPollOptions()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "poll_choices";
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
                FROM        " . $this->databasePrefix . "poll_choices
                ORDER BY    id_poll, id_choice";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $data = [
                'pollID' => $row['id_poll'],
                'optionValue' => $row['label'],
                'showOrder' => $row['id_choice'],
                'votes' => $row['votes'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.poll.option')
                ->import(
                    ($row['id_poll'] . '-' . $row['id_choice']),
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
                FROM    " . $this->databasePrefix . "log_polls
                WHERE   id_member <> ?";
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
                FROM        " . $this->databasePrefix . "log_polls
                WHERE       id_member <> ?
                ORDER BY    id_poll, id_member, id_choice";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute([0]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'pollID' => $row['id_poll'],
                'optionID' => $row['id_poll'] . '-' . $row['id_choice'],
                'userID' => $row['id_member'],
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
        return 1;
    }

    /**
     * Exports ACLs.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportACLs($offset, $limit)
    {
        // TODO: try to split this into several requests
        $profileToBoard = [];
        $boardToGroup = [];
        $boardToMod = [];

        $sql = "SELECT      id_board, id_profile, member_groups,
                            (
                                SELECT  GROUP_CONCAT(id_member)
                                FROM    " . $this->databasePrefix . "moderators moderator
                                WHERE   moderator.id_board = board.id_board
                            ) AS moderators
                FROM        " . $this->databasePrefix . "boards board
                ORDER BY    id_board";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            if (!isset($profileToBoard[$row['id_profile']])) {
                $profileToBoard[$row['id_profile']] = [];
            }
            $profileToBoard[$row['id_profile']][] = $row['id_board'];

            $boardToGroup[$row['id_board']] = \array_unique(
                ArrayUtil::toIntegerArray(\explode(',', $row['member_groups']))
            );
            if ($row['moderators'] !== null) {
                $boardToMod[$row['id_board']] = \array_unique(
                    ArrayUtil::toIntegerArray(\explode(',', $row['moderators']))
                );
            }
        }

        foreach ($boardToGroup as $boardID => $groups) {
            // deny for everyone first
            ImportHandler::getInstance()->getImporter('com.woltlab.wbb.acl')->import(0, [
                'objectID' => $boardID,
                'groupID' => self::GROUP_EVERYONE,
                'optionValue' => 0,
            ], [
                'optionName' => 'canViewBoard',
            ]);
            ImportHandler::getInstance()->getImporter('com.woltlab.wbb.acl')->import(0, [
                'objectID' => $boardID,
                'groupID' => self::GROUP_EVERYONE,
                'optionValue' => 0,
            ], [
                'optionName' => 'canEnterBoard',
            ]);

            if (!\in_array(self::GROUP_ADMIN, $groups)) {
                // admins may do everything
                $groups[] = self::GROUP_ADMIN;
            }

            foreach ($groups as $groupID) {
                $groupID = $groupID == self::GROUP_USER ? self::GROUP_USER_FAKE : $groupID;

                // allow specified groups
                ImportHandler::getInstance()->getImporter('com.woltlab.wbb.acl')->import(0, [
                    'objectID' => $boardID,
                    'groupID' => $groupID,
                    'optionValue' => 1,
                ], [
                    'optionName' => 'canViewBoard',
                ]);
                ImportHandler::getInstance()->getImporter('com.woltlab.wbb.acl')->import(0, [
                    'objectID' => $boardID,
                    'groupID' => $groupID,
                    'optionValue' => 1,
                ], [
                    'optionName' => 'canEnterBoard',
                ]);
            }
        }

        static $permissionMap = [
            'approve_posts' => [
                'canEnableThread',
                'canEnablePost',
            ],
            'delete_any' => [
                'canDeletePost',
                'canReadDeletedPost',
                'canRestorePost',
                'canDeletePostCompletely',
            ],
            'delete_own' => ['canDeleteOwnPost'],
            'lock_any' => ['canCloseThread', 'canClosePost'],
            'lock_own' => [],
            'make_sticky' => ['canPinThread'],
            'mark_any_modify' => [],
            'mark_modify' => [],
            'merge_any' => ['canMergeThread'],
            'moderate_board' => ['canReplyClosedThread'],
            'modify_any' => ['canEditPost'],
            'modify_own' => ['canEditOwnPost'],
            'poll_add_any' => [],
            'poll_add_own' => [],
            'poll_edit_any' => [],
            'poll_edit_own' => [],
            'poll_lock_any' => [],
            'poll_lock_own' => [],
            'poll_post' => ['canStartPoll'],
            'poll_remove_any' => [],
            'poll_view' => [],
            'poll_vote' => ['canVotePoll'],
            'post_attachment' => ['canUploadAttachment'],
            'post_reply_any' => ['canReplyThread'],
            'post_reply_own' => ['canReplyOwnThread'],
            'post_unapproved_replies_any' => ['canReplyThreadWithoutModeration'],
            'post_unapproved_replies_own' => [],
            'post_unapproved_topics' => ['canStartThreadWithoutModeration'],
            'remove_any' => [
                'canDeleteThread',
                'canReadDeletedThread',
                'canRestoreThread',
                'canDeleteThreadCompletely',
            ],
            'remove_own' => [],
            'report_any' => [],
            'send_topic' => [],
            'split_any' => [],
            'view_attachments' => ['canDownloadAttachment', 'canViewAttachmentPreview'],
        ];

        $sql = "SELECT      *
                FROM        " . $this->databasePrefix . "board_permissions
                ORDER BY    id_group, id_profile, permission";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            if (!isset($profileToBoard[$row['id_profile']])) {
                continue;
            }
            if (!isset($permissionMap[$row['permission']])) {
                continue;
            }

            foreach ($profileToBoard[$row['id_profile']] as $boardID) {
                foreach ($permissionMap[$row['permission']] as $permission) {
                    if ($row['id_group'] == self::GROUP_MODERATORS) {
                        // import individual mods, instead of group

                        if (!isset($boardToMod[$boardID])) {
                            continue;
                        }
                        foreach ($boardToMod[$boardID] as $moderator) {
                            ImportHandler::getInstance()->getImporter('com.woltlab.wbb.acl')->import(0, [
                                'objectID' => $boardID,
                                'userID' => $moderator,
                                'optionValue' => $row['add_deny'],
                            ], [
                                'optionName' => $permission,
                            ]);
                        }
                    } else {
                        $groupID = $row['id_group'] == self::GROUP_USER ? self::GROUP_USER_FAKE : $row['id_group'];

                        ImportHandler::getInstance()->getImporter('com.woltlab.wbb.acl')->import(0, [
                            'objectID' => $boardID,
                            'groupID' => $groupID,
                            'optionValue' => $row['add_deny'],
                        ], [
                            'optionName' => $permission,
                        ]);
                    }
                }
            }
        }

        // admins may do everything
        $boardIDs = \array_keys($boardToGroup);
        foreach ($boardIDs as $boardID) {
            foreach ($permissionMap as $permissions) {
                foreach ($permissions as $permission) {
                    ImportHandler::getInstance()->getImporter('com.woltlab.wbb.acl')->import(0, [
                        'objectID' => $boardID,
                        'groupID' => self::GROUP_ADMIN,
                        'optionValue' => 1,
                    ], [
                        'optionName' => $permission,
                    ]);
                }
            }
        }
    }

    /**
     * Counts smilies.
     */
    public function countSmilies()
    {
        $sql = "SELECT  COUNT(DISTINCT filename) AS count
                FROM    " . $this->databasePrefix . "smileys";
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
        $sql = "SELECT      MIN(id_smiley) AS id_smiley,
                            GROUP_CONCAT(code SEPARATOR '\n') AS aliases,
                            filename,
                            MIN(smiley_order) AS smiley_order,
                            GROUP_CONCAT(description SEPARATOR '\n') AS description
                FROM        " . $this->databasePrefix . "smileys
                GROUP BY    filename
                ORDER BY    id_smiley";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute([]);
        while ($row = $statement->fetchArray()) {
            $fileLocation = $this->readOption('smiley_dir') . '/' . $this->readOption('smiley_sets_default') . '/' . $row['filename'];

            $aliases = \explode("\n", $row['aliases']);
            $code = \array_shift($aliases);
            // we had to GROUP_CONCAT it because of SQL strict mode
            $description = \mb_substr(
                $row['description'],
                0,
                (\mb_strpos($row['description'], "\n") ?: \mb_strlen($row['description']))
            );

            $data = [
                'smileyTitle' => $description,
                'smileyCode' => $code,
                'showOrder' => $row['smiley_order'],
                'aliases' => \implode("\n", $aliases),
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.smiley')
                ->import(
                    $row['id_smiley'],
                    $data,
                    ['fileLocation' => $fileLocation]
                );
        }
    }

    /**
     * Returns the value of the given option in the imported board.
     *
     * @param   string      $optionName
     * @return  mixed
     */
    private function readOption($optionName)
    {
        static $optionCache = [];

        if (!isset($optionCache[$optionName])) {
            $sql = "SELECT  value
                    FROM    " . $this->databasePrefix . "settings
                    WHERE   variable = ?";
            $statement = $this->database->prepareStatement($sql);
            $statement->execute([$optionName]);
            $row = $statement->fetchArray();

            $optionCache[$optionName] = ($row !== false ? $row['value'] : '');
        }

        return $optionCache[$optionName];
    }

    /**
     * Returns message with fixed BBCodes as used in WCF.
     *
     * @param   string      $message
     * @return  string
     */
    private static function fixBBCodes($message)
    {
        static $sizeRegex = null;
        static $quoteRegex = null;
        static $quoteCallback = null;

        if ($sizeRegex === null) {
            $quoteRegex = new Regex('\[quote author=(.*?)(?: link=topic=\d+\.msg(\d+)#msg\\2 date=\d+)?\]');
            $quoteCallback = static function ($matches) {
                $username = \str_replace(["\\", "'"], ["\\\\", "\\'"], $matches[1]);
                $postID = $matches[2] ?? null;

                if ($postID) {
                    $postLink = LinkHandler::getInstance()->getLink('Thread', [
                        'application' => 'wbb',
                        'postID' => $postID,
                        'forceFrontend' => true,
                    ]) . '#post' . $postID;
                    $postLink = \str_replace(["\\", "'"], ["\\\\", "\\'"], $postLink);

                    return "[quote='" . $username . "','" . $postLink . "']";
                } else {
                    return "[quote='" . $username . "']";
                }
            };

            $sizeRegex = new Regex('\[size=(8|10|12|14|18|24|34)pt\]');
        }

        // remove unsupported attributes in img tags
        $message = \preg_replace('~(\[img[^]]*)\s+width=\d+([^]]*\])~i', '\\1\\2', $message);
        $message = \preg_replace('~(\[img[^]]*)\s+height=\d+([^]]*\])~i', '\\1\\2', $message);

        // use proper WCF 2 bbcode
        $message = \strtr($message, [
            '<br />' => "\n",
            '[iurl]' => '[url]',
            '[/iurl]' => '[/url]',
            '[left]' => '[align=left]',
            '[/left]' => '[/align]',
            '[right]' => '[align=right]',
            '[/right]' => '[/align]',
            '[center]' => '[align=center]',
            '[/center]' => '[/align]',
            '[ftp]' => '[url]',
            '[/ftp]' => '[/url]',
            '[php]' => '[code=php]',
            '[/php]' => '[/code]',
        ]);

        // fix size bbcode
        $message = $sizeRegex->replace($message, '[size=\\1]');

        // convert html entities in text
        $message = StringUtil::decodeHTML($message);

        // quotes
        $message = $quoteRegex->replace($message, $quoteCallback);

        // remove crap
        $message = MessageUtil::stripCrap($message);

        return $message;
    }

    private function getAttachmentFilename($id, $dir, $hash, $filename)
    {
        if (!empty($this->readOption('currentAttachmentUploadDir'))) {
            // multiple attachments dir
            static $dirs;
            if ($dirs === null) {
                $dirs = \unserialize($this->readOption('attachmentUploadDir'));
            }

            if (isset($dirs[$dir])) {
                $path = $dirs[$dir];
            } else {
                $path = $this->fileSystemPath . 'attachments';
            }
        } else {
            $path = $this->readOption('attachmentUploadDir');
        }

        if ($hash) {
            return $path . '/' . $id . '_' . $hash;
        } else {
            // sanitize spaces
            $filename = \preg_replace('/\s/', '_', $filename);
            // strip special characters
            $filename = \preg_replace('/[^\w_\.\-]/', '', $filename);

            $scrambled = $id . '_' . \str_replace('.', '_', $filename) . \md5($filename);
            if (\file_exists($path . '/' . $scrambled)) {
                return $path . '/' . $scrambled;
            }

            // collapsed consecutive dots
            $filename = \preg_replace('/\.{2,}/', '.', $filename);

            return $path . '/' . $filename;
        }
    }
}
