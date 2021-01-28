<?php

namespace wcf\system\exporter;

use wcf\data\object\type\ObjectTypeCache;
use wcf\data\user\group\UserGroup;
use wcf\data\user\option\UserOption;
use wcf\system\database\util\PreparedStatementConditionBuilder;
use wcf\system\importer\ImportHandler;
use wcf\system\WCF;
use wcf\util\FileUtil;
use wcf\util\MessageUtil;
use wcf\util\StringUtil;
use wcf\util\UserUtil;

/**
 * Exporter for Burning Board 2.x
 *
 * @author  Marcel Werk
 * @copyright   2001-2019 WoltLab GmbH
 * @license GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package WoltLabSuite\Core\System\Exporter
 */
class WBB2xExporter extends AbstractExporter
{
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
        'com.woltlab.wcf.user.avatar' => 'UserAvatars',
        'com.woltlab.wcf.user.option' => 'UserOptions',
        'com.woltlab.wcf.conversation.label' => 'ConversationFolders',
        'com.woltlab.wcf.conversation' => 'Conversations',
        'com.woltlab.wcf.conversation.user' => 'ConversationUsers',
        'com.woltlab.wcf.conversation.attachment' => 'ConversationAttachments',
        'com.woltlab.wbb.board' => 'Boards',
        'com.woltlab.wbb.thread' => 'Threads',
        'com.woltlab.wbb.post' => 'Posts',
        'com.woltlab.wbb.attachment' => 'PostAttachments',
        'com.woltlab.wbb.watchedThread' => 'WatchedThreads',
        'com.woltlab.wbb.poll' => 'Polls',
        'com.woltlab.wbb.poll.option' => 'PollOptions',
        'com.woltlab.wcf.label' => 'Labels',
        'com.woltlab.wbb.acl' => 'ACLs',
        'com.woltlab.wcf.smiley' => 'Smilies',
    ];

    protected $permissionMap = [
        'can_view_board' => 'canViewBoard',
        'can_enter_board' => 'canEnterBoard',
        'can_read_thread' => 'canReadThread',
        'can_start_topic' => 'canStartThread',
        'can_reply_topic' => 'canReplyThread',
        'can_reply_own_topic' => 'canReplyOwnThread',
        'can_post_poll' => 'canStartPoll',
        'can_upload_attachments' => 'canUploadAttachment',
        'can_download_attachments' => 'canDownloadAttachment',
        'can_post_without_moderation' => 'canReplyThreadWithoutModeration',
        //'can_close_own_topic' => '',
        //'can_use_search' => '',
        'can_vote_poll' => 'canVotePoll',
        //'can_rate_thread' => '',
        'can_del_own_post' => 'canDeleteOwnPost',
        'can_edit_own_post' => 'canEditOwnPost',
        //'can_del_own_topic' => '',
        //'can_edit_own_topic' => '',
        //'can_move_own_topic' => '',
        //'can_use_post_html' => '',
        //'can_use_post_bbcode' => '',
        //'can_use_post_smilies' => '',
        //'can_use_post_icons' => '',
        //'can_use_post_images' => '',
        //'can_use_prefix' => ''
    ];

    /**
     * @inheritDoc
     */
    public function validateDatabaseAccess()
    {
        parent::validateDatabaseAccess();

        $sql = "SELECT COUNT(*) FROM " . $this->databasePrefix . "posts";
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
            || \in_array('com.woltlab.wcf.smiley', $this->selectedData)
        ) {
            if (empty($this->fileSystemPath) || !@\file_exists($this->fileSystemPath . 'newthread.php')) {
                return false;
            }
        }

        return true;
    }

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
                'com.woltlab.wcf.user.rank',
            ],
            'com.woltlab.wbb.board' => [
                'com.woltlab.wbb.acl',
                'com.woltlab.wbb.attachment',
                'com.woltlab.wbb.poll',
                'com.woltlab.wbb.watchedThread',
                'com.woltlab.wcf.label',
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

            // conversation
            if (\in_array('com.woltlab.wcf.conversation', $this->selectedData)) {
                if (\in_array('com.woltlab.wcf.conversation.label', $this->selectedData)) {
                    $queue[] = 'com.woltlab.wcf.conversation.label';
                }

                $queue[] = 'com.woltlab.wcf.conversation';
                $queue[] = 'com.woltlab.wcf.conversation.user';

                if (\in_array('com.woltlab.wcf.conversation.attachment', $this->selectedData)) {
                    $queue[] = 'com.woltlab.wcf.conversation.attachment';
                }
            }
        }

        // board
        if (\in_array('com.woltlab.wbb.board', $this->selectedData)) {
            $queue[] = 'com.woltlab.wbb.board';
            if (\in_array('com.woltlab.wcf.label', $this->selectedData)) {
                $queue[] = 'com.woltlab.wcf.label';
            }
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
        return 'bb1_';
    }

    /**
     * Counts user groups.
     */
    public function countUserGroups()
    {
        $sql = "SELECT	COUNT(*) AS count
			FROM	" . $this->databasePrefix . "groups";
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
        $sql = "SELECT		*
			FROM		" . $this->databasePrefix . "groups
			ORDER BY	groupid";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $groupType = 4;
            switch ($row['grouptype']) {
                case 1: // guests
                    $groupType = UserGroup::GUESTS;
                    break;

                case 4: // users
                    $groupType = UserGroup::USERS;
                    break;
                case 5: // open group
                case 6: // moderated group
                    $groupType = $row['grouptype'];
                    break;
            }

            $data = [
                'groupName' => $row['title'],
                'groupType' => $groupType,
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.user.group')
                ->import($row['groupid'], $data);
        }
    }

    /**
     * Counts users.
     */
    public function countUsers()
    {
        return $this->__getMaxID($this->databasePrefix . "users", 'userid');
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
        $sql = "SELECT	profilefieldid
			FROM	" . $this->databasePrefix . "profilefields
			WHERE	profilefieldid > 3";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $profileFields[] = $row['profilefieldid'];
        }

        // prepare password update
        $sql = "UPDATE	wcf" . WCF_N . "_user
			SET	password = ?
			WHERE	userID = ?";
        $passwordUpdateStatement = WCF::getDB()->prepareStatement($sql);

        // get users
        $sql = "SELECT		userfields.*, user.*,
					(
						SELECT	GROUP_CONCAT(groupid)
						FROM	" . $this->databasePrefix . "user2groups
						WHERE	userid = user.userid
					) AS groupIDs
			FROM		" . $this->databasePrefix . "users user
			LEFT JOIN	" . $this->databasePrefix . "userfields userfields
			ON		(userfields.userid = user.userid)
			WHERE		user.userid BETWEEN ? AND ?
			ORDER BY	user.userid";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'username' => $row['username'],
                'password' => null,
                'email' => $row['email'],
                'registrationDate' => $row['regdate'],
                'signature' => self::fixBBCodes($row['signature']),
                'lastActivityTime' => $row['lastactivity'],
                'userTitle' => $row['title'],
                'disableSignature' => $row['disablesignature'],
                'banned' => $row['blocked'],
                'signatureEnableHtml' => $row['allowsightml'],
                'registrationIpAddress' => !empty($row['reg_ipaddress']) ? $row['reg_ipaddress'] : '',
            ];

            $options = [
                'birthday' => $row['birthday'],
                'gender' => $row['gender'],
                'homepage' => $row['homepage'],
                'icq' => $row['icq'] ? $row['icq'] : '',
                'location' => !empty($row['field1']) ? $row['field1'] : '',
                'hobbies' => !empty($row['field2']) ? $row['field2'] : '',
                'occupation' => !empty($row['field3']) ? $row['field3'] : '',
            ];

            foreach ($profileFields as $profileFieldID) {
                if (!empty($row['field' . $profileFieldID])) {
                    $options[$profileFieldID] = $row['field' . $profileFieldID];
                }
            }

            $additionalData = [
                'groupIDs' => \explode(',', $row['groupIDs']),
                'options' => $options,
            ];

            // import user
            $newUserID = ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.user')
                ->import(
                    $row['userid'],
                    $data,
                    $additionalData
                );

            // update password hash
            if ($newUserID) {
                $password = \sprintf(
                    'wbb2:%s',
                    (!empty($row['sha1_password']) ? $row['sha1_password'] : $row['password'])
                );

                $passwordUpdateStatement->execute([
                    $password,
                    $newUserID,
                ]);
            }
        }
    }

    /**
     * Counts user ranks.
     */
    public function countUserRanks()
    {
        $sql = "SELECT	COUNT(*) AS count
			FROM	" . $this->databasePrefix . "ranks";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute();
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
        $sql = "SELECT		*
			FROM		" . $this->databasePrefix . "ranks
			ORDER BY	rankid";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $data = [
                'groupID' => $row['groupid'],
                'requiredPoints' => $row['needposts'] * 5,
                'rankTitle' => $row['ranktitle'],
                'requiredGender' => $row['gender'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.user.rank')
                ->import($row['rankid'], $data);
        }
    }

    /**
     * Counts user avatars.
     */
    public function countUserAvatars()
    {
        $sql = "SELECT	COUNT(*) AS count
			FROM	" . $this->databasePrefix . "avatars
			WHERE	userid <> ?";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([0]);
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
        $sql = "SELECT		*
			FROM		" . $this->databasePrefix . "avatars
			WHERE		userid <> ?
			ORDER BY	avatarid";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute([0]);
        while ($row = $statement->fetchArray()) {
            $fileLocation = $this->fileSystemPath . 'images/avatars/avatar-' . $row['avatarid'] . '.' . $row['avatarextension'];

            $data = [
                'avatarName' => $row['avatarname'],
                'avatarExtension' => $row['avatarextension'],
                'width' => $row['width'],
                'height' => $row['height'],
                'userID' => $row['userid'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.user.avatar')
                ->import(
                    $row['avatarid'],
                    $data,
                    ['fileLocation' => $fileLocation]
                );
        }
    }

    /**
     * Counts user options.
     */
    public function countUserOptions()
    {
        $sql = "SELECT	COUNT(*) AS count
			FROM	" . $this->databasePrefix . "profilefields
			WHERE	profilefieldid > ?";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([3]);
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
        $sql = "SELECT		*
			FROM		" . $this->databasePrefix . "profilefields
			WHERE		profilefieldid > ?
			ORDER BY	profilefieldid";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([3]);
        while ($row = $statement->fetchArray()) {
            $optionType = 'text';
            switch ($row['fieldtype']) {
                case 'select':
                    $optionType = 'select';
                    break;
                case 'multiselect':
                    $optionType = 'multiSelect';
                    break;
                case 'checkbox':
                    $optionType = 'boolean';
                    break;
                case 'date':
                    $optionType = 'date';
                    break;
            }

            $data = [
                'categoryName' => 'profile.personal',
                'optionType' => $optionType,
                'required' => $row['required'],
                'visible' => $row['hidden'] ? 0 : UserOption::VISIBILITY_ALL,
                'showOrder' => $row['fieldorder'],
                'selectOptions' => $row['fieldoptions'],
                'editable' => UserOption::EDITABILITY_ALL,
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.user.option')
                ->import(
                    $row['profilefieldid'],
                    $data,
                    ['name' => $row['title']]
                );
        }
    }

    /**
     * Counts conversation folders.
     */
    public function countConversationFolders()
    {
        $sql = "SELECT	COUNT(*) AS count
			FROM	" . $this->databasePrefix . "folders";
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
        $sql = "SELECT		*
			FROM		" . $this->databasePrefix . "folders
			ORDER BY	folderid";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $data = [
                'userID' => $row['userid'],
                'label' => \mb_substr($row['title'], 0, 80),
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.conversation.label')
                ->import($row['folderid'], $data);
        }
    }

    /**
     * Counts conversations.
     */
    public function countConversations()
    {
        return $this->__getMaxID($this->databasePrefix . "privatemessage", 'privatemessageid');
    }

    /**
     * Exports conversations.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportConversations($offset, $limit)
    {
        $sql = "SELECT		pm.*, user_table.username
			FROM		" . $this->databasePrefix . "privatemessage pm
			LEFT JOIN	" . $this->databasePrefix . "users user_table
			ON		(user_table.userid = pm.senderid)
			WHERE		pm.privatemessageid BETWEEN ? AND ?
			ORDER BY	pm.privatemessageid";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'subject' => $row['subject'],
                'time' => $row['sendtime'],
                'userID' => $row['senderid'],
                'username' => $row['username'] ?: '',
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.conversation')
                ->import($row['privatemessageid'], $data);

            // import message
            $data = [
                'conversationID' => $row['privatemessageid'],
                'userID' => $row['senderid'],
                'username' => $row['username'] ?: '',
                'message' => self::fixBBCodes($row['message']),
                'time' => $row['sendtime'],
                'attachments' => $row['attachments'],
                'enableHtml' => $row['allowhtml'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.conversation.message')
                ->import($row['privatemessageid'], $data);
        }
    }

    /**
     * Counts conversation recipients.
     */
    public function countConversationUsers()
    {
        $sql = "SELECT	COUNT(*) AS count
			FROM	" . $this->databasePrefix . "privatemessagereceipts";
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
        $sql = "SELECT		*
			FROM		" . $this->databasePrefix . "privatemessagereceipts
			ORDER BY	privatemessageid DESC, recipientid";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $data = [
                'conversationID' => $row['privatemessageid'],
                'participantID' => $row['recipientid'],
                'username' => $row['recipient'],
                'hideConversation' => $row['deletepm'],
                'isInvisible' => $row['blindcopy'],
                'lastVisitTime' => $row['view'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.conversation.user')
                ->import(
                    0,
                    $data,
                    ['labelIDs' => $row['folderid'] ? [$row['folderid']] : []]
                );
        }
    }

    /**
     * Counts conversation attachments.
     */
    public function countConversationAttachments()
    {
        return $this->countAttachments('privatemessageid');
    }

    /**
     * Exports conversation attachments.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportConversationAttachments($offset, $limit)
    {
        $this->exportAttachments('privatemessageid', 'com.woltlab.wcf.conversation.attachment', $offset, $limit);
    }

    /**
     * Counts boards.
     */
    public function countBoards()
    {
        $sql = "SELECT	COUNT(*) AS count
			FROM	" . $this->databasePrefix . "boards";
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
        $sql = "SELECT		*
			FROM		" . $this->databasePrefix . "boards
			ORDER BY	parentid, boardorder";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $this->boardCache[$row['parentid']][] = $row;
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
                'parentID' => $board['parentid'] ?: null,
                'position' => $board['boardorder'],
                'boardType' => !$board['isboard'] ? 1 : (!empty($board['externalurl']) ? 2 : 0),
                'title' => $board['title'],
                'description' => $board['description'],
                'externalURL' => (!empty($board['externalurl']) ? $board['externalurl'] : ''),
                'countUserPosts' => $board['countuserposts'],
                'isClosed' => $board['closed'],
                'isInvisible' => \intval($board['invisible'] == 2),
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.board')
                ->import($board['boardid'], $data);

            $this->exportBoardsRecursively($board['boardid']);
        }
    }

    /**
     * Counts threads.
     */
    public function countThreads()
    {
        return $this->__getMaxID($this->databasePrefix . "threads", 'threadid');
    }

    /**
     * Exports threads.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportThreads($offset, $limit)
    {
        // get global prefixes
        $globalPrefixes = '';
        $sql = "SELECT	value
			FROM	" . $this->databasePrefix . "options
			WHERE	varname = ?";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute(['default_prefix']);
        $row = $statement->fetchArray();
        if ($row !== false) {
            $globalPrefixes = $row['value'];
        }

        // get boards
        $boardPrefixes = [];

        $sql = "SELECT	boardid, prefix, prefixuse
			FROM	" . $this->databasePrefix . "boards
			WHERE	prefixuse > ?";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([0]);
        while ($row = $statement->fetchArray()) {
            $prefixes = '';

            switch ($row['prefixuse']) {
                case 1:
                    $prefixes = $globalPrefixes;
                    break;
                case 2:
                    $prefixes = $globalPrefixes . "\n" . $row['prefix'];
                    break;
                case 3:
                    $prefixes = $row['prefix'];
                    break;
            }

            $prefixes = StringUtil::trim(StringUtil::unifyNewlines($prefixes));
            if ($prefixes) {
                $key = StringUtil::getHash($prefixes);
                $boardPrefixes[$row['boardid']] = $key;
            }
        }

        // get thread ids
        $threadIDs = $announcementIDs = [];
        $sql = "SELECT		threadid, important
			FROM		" . $this->databasePrefix . "threads
			WHERE		threadid BETWEEN ? AND ?
			ORDER BY	threadid";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $threadIDs[] = $row['threadid'];
            if ($row['important'] == 2) {
                $announcementIDs[] = $row['threadid'];
            }
        }

        // get assigned boards (for announcements)
        $assignedBoards = [];
        if (!empty($announcementIDs)) {
            $conditionBuilder = new PreparedStatementConditionBuilder();
            $conditionBuilder->add('threadid IN (?)', [$announcementIDs]);

            $sql = "SELECT		boardid, threadid
				FROM		" . $this->databasePrefix . "announcements
				" . $conditionBuilder;
            $statement = $this->database->prepareStatement($sql);
            $statement->execute($conditionBuilder->getParameters());
            while ($row = $statement->fetchArray()) {
                if (!isset($assignedBoards[$row['threadid']])) {
                    $assignedBoards[$row['threadid']] = [];
                }
                $assignedBoards[$row['threadid']][] = $row['boardid'];
            }
        }

        if (empty($threadIDs)) {
            return;
        }

        // get threads
        $conditionBuilder = new PreparedStatementConditionBuilder();
        $conditionBuilder->add('threadid IN (?)', [$threadIDs]);

        $sql = "SELECT		*
			FROM		" . $this->databasePrefix . "threads
			" . $conditionBuilder;
        $statement = $this->database->prepareStatement($sql);
        $statement->execute($conditionBuilder->getParameters());
        while ($row = $statement->fetchArray()) {
            $data = [
                'boardID' => $row['boardid'],
                'topic' => $row['topic'],
                'time' => $row['starttime'],
                'userID' => $row['starterid'],
                'username' => $row['starter'],
                'views' => $row['views'],
                'isAnnouncement' => \intval($row['important'] == 2),
                'isSticky' => \intval($row['important'] == 1),
                'isDisabled' => \intval(!$row['visible']),
                'isClosed' => \intval($row['closed'] == 1),
                'movedThreadID' => ($row['closed'] == 3) ? $row['pollid'] : null,
                'lastPostTime' => $row['lastposttime'],
            ];
            $additionalData = [];
            if (!empty($assignedBoards[$row['threadid']])) {
                $additionalData['assignedBoards'] = $assignedBoards[$row['threadid']];
            }
            if ($row['prefix'] && isset($boardPrefixes[$row['boardid']])) {
                $additionalData['labels'] = [$boardPrefixes[$row['boardid']] . '-' . $row['prefix']];
            }

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.thread')
                ->import(
                    $row['threadid'],
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
        return $this->__getMaxID($this->databasePrefix . "posts", 'postid');
    }

    /**
     * Exports posts.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportPosts($offset, $limit)
    {
        $sql = "SELECT		*
			FROM		" . $this->databasePrefix . "posts
			WHERE		postid BETWEEN ? AND ?
			ORDER BY	postid";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'threadID' => $row['threadid'],
                'userID' => $row['userid'],
                'username' => $row['username'],
                'subject' => $row['posttopic'],
                'message' => self::fixBBCodes($row['message']),
                'time' => $row['posttime'],
                'isDisabled' => \intval(!$row['visible']),
                'editorID' => $row['editorid'] ?: null,
                'editor' => $row['editor'],
                'lastEditTime' => $row['edittime'],
                'editCount' => $row['editcount'],
                'attachments' => (!empty($row['attachments']) ? $row['attachments'] : 0),
                'enableHtml' => $row['allowhtml'],
                'ipAddress' => UserUtil::convertIPv4To6($row['ipaddress']),
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.post')
                ->import($row['postid'], $data);
        }
    }

    /**
     * Counts post attachments.
     */
    public function countPostAttachments()
    {
        return $this->countAttachments('postid');
    }

    /**
     * Exports post attachments.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportPostAttachments($offset, $limit)
    {
        $this->exportAttachments('postid', 'com.woltlab.wbb.attachment', $offset, $limit);
    }

    /**
     * Counts watched threads.
     */
    public function countWatchedThreads()
    {
        $sql = "SELECT	COUNT(*) AS count
			FROM	" . $this->databasePrefix . "subscribethreads";
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
        $sql = "SELECT		*
			FROM		" . $this->databasePrefix . "subscribethreads
			ORDER BY	userid, threadid";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $data = [
                'objectID' => $row['threadid'],
                'userID' => $row['userid'],
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
        $sql = "SELECT	COUNT(*) AS count
			FROM	" . $this->databasePrefix . "polls";
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
        // prepare statements
        $sql = "SELECT		postid
			FROM		" . $this->databasePrefix . "posts
			WHERE		threadid = ?
			ORDER BY	posttime";
        $firstPostStatement = $this->database->prepareStatement($sql, 1);
        $sql = "SELECT		COUNT(*) AS votes
			FROM		" . $this->databasePrefix . "votes
			WHERE		id = ?
					AND votemode = 1";
        $votesStatement = $this->database->prepareStatement($sql);

        $sql = "SELECT		*
			FROM		" . $this->databasePrefix . "polls poll
			ORDER BY	pollid";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $postID = null;
            $votes = 0;

            // get first post id
            $firstPostStatement->execute([$row['threadid']]);
            $row2 = $firstPostStatement->fetchArray();
            if (empty($row2['postid'])) {
                continue;
            }
            $postID = $row2['postid'];

            // get votes
            $votesStatement->execute([$row['pollid']]);
            $row2 = $votesStatement->fetchArray();
            if (!empty($row2['votes'])) {
                $votes = $row2['votes'];
            }

            $data = [
                'objectID' => $postID,
                'question' => $row['question'],
                'time' => $row['starttime'],
                'endTime' => $row['timeout'] ? $row['starttime'] + $row['timeout'] * 86400 : 0,
                'maxVotes' => $row['choicecount'],
                'votes' => $votes,
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.poll')
                ->import($row['pollid'], $data);
        }
    }

    /**
     * Counts poll options.
     */
    public function countPollOptions()
    {
        $sql = "SELECT	COUNT(*) AS count
			FROM	" . $this->databasePrefix . "polloptions";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([]);
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
        $sql = "SELECT		*
			FROM		" . $this->databasePrefix . "polloptions
			ORDER BY	polloptionid";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute([]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'pollID' => $row['pollid'],
                'optionValue' => $row['polloption'],
                'votes' => $row['votes'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.poll.option')
                ->import($row['polloptionid'], $data);
        }
    }

    /**
     * Counts labels.
     */
    public function countLabels()
    {
        $sql = "SELECT	COUNT(*) AS count
			FROM	" . $this->databasePrefix . "boards
			WHERE	prefixuse > ?";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([0]);
        $row = $statement->fetchArray();

        return $row['count'] ? 1 : 0;
    }

    /**
     * Exports labels.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportLabels($offset, $limit)
    {
        $prefixMap = [];

        // get global prefixes
        $globalPrefixes = '';
        $sql = "SELECT	value
			FROM	" . $this->databasePrefix . "options
			WHERE	varname = ?";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute(['default_prefix']);
        $row = $statement->fetchArray();
        if ($row !== false) {
            $globalPrefixes = $row['value'];
        }

        // get boards
        $sql = "SELECT	boardid, prefix, prefixuse
			FROM	" . $this->databasePrefix . "boards
			WHERE	prefixuse > ?";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([0]);
        while ($row = $statement->fetchArray()) {
            $prefixes = '';

            switch ($row['prefixuse']) {
                case 1:
                    $prefixes = $globalPrefixes;
                    break;
                case 2:
                    $prefixes = $globalPrefixes . "\n" . $row['prefix'];
                    break;
                case 3:
                    $prefixes = $row['prefix'];
                    break;
            }

            $prefixes = StringUtil::trim(StringUtil::unifyNewlines($prefixes));
            if ($prefixes) {
                $key = StringUtil::getHash($prefixes);
                if (!isset($prefixMap[$key])) {
                    $prefixMap[$key] = [
                        'prefixes' => $prefixes,
                        'boardIDs' => [],
                    ];
                }

                $boardID = ImportHandler::getInstance()->getNewID('com.woltlab.wbb.board', $row['boardid']);
                if ($boardID) {
                    $prefixMap[$key]['boardIDs'][] = $boardID;
                }
            }
        }

        // save prefixes
        if (!empty($prefixMap)) {
            $i = 1;
            $objectType = ObjectTypeCache::getInstance()
                ->getObjectTypeByName('com.woltlab.wcf.label.objectType', 'com.woltlab.wbb.board');

            foreach ($prefixMap as $key => $data) {
                // import label group
                $data = [
                    'groupName' => 'labelgroup' . $i,
                ];

                $additionalData = [
                    'objects' => [
                        $objectType->objectTypeID => $data['boardIDs'],
                    ],
                ];

                ImportHandler::getInstance()
                    ->getImporter('com.woltlab.wcf.label.group')
                    ->import(
                        $key,
                        $data,
                        $additionalData
                    );

                // import labels
                $labels = \explode("\n", $data['prefixes']);
                foreach ($labels as $label) {
                    $data = [
                        'groupID' => $key,
                        'label' => \mb_substr($label, 0, 80),
                    ];

                    ImportHandler::getInstance()
                        ->getImporter('com.woltlab.wcf.label')
                        ->import($key . '-' . $label, $data);
                }

                $i++;
            }
        }
    }

    /**
     * Counts ACLs.
     */
    public function countACLs()
    {
        $sql = "SELECT	COUNT(*) AS count
			FROM	" . $this->databasePrefix . "permissions";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute();
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Exports ACLs.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportACLs($offset, $limit)
    {
        $sql = "SELECT		*
			FROM		" . $this->databasePrefix . "permissions
			ORDER BY	boardid, groupid";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $data = [
                'objectID' => $row['boardid'],
                'groupID' => $row['groupid'],
            ];
            unset($row['boardid'], $row['groupid']);

            foreach ($row as $permission => $value) {
                if ($value == -1) {
                    continue;
                }
                if (!isset($this->permissionMap[$permission])) {
                    continue;
                }

                ImportHandler::getInstance()
                    ->getImporter('com.woltlab.wbb.acl')
                    ->import(
                        0,
                        \array_merge($data, ['optionValue' => $value]),
                        [
                            'optionName' => $this->permissionMap[$permission],
                        ]
                    );
            }
        }
    }

    /**
     * Counts smilies.
     */
    public function countSmilies()
    {
        $sql = "SELECT	COUNT(*) AS count
			FROM	" . $this->databasePrefix . "smilies";
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
        $sql = "SELECT		*
			FROM		" . $this->databasePrefix . "smilies
			ORDER BY	smilieid";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            // replace imagefolder
            $row['smiliepath'] = \str_replace('{imagefolder}', 'images', $row['smiliepath']);

            // insert source path
            if (!FileUtil::isURL($row['smiliepath'])) {
                $row['smiliepath'] = $this->fileSystemPath . $row['smiliepath'];
            }

            $data = [
                'smileyTitle' => $row['smilietitle'],
                'smileyCode' => $row['smiliecode'],
                'showOrder' => $row['smilieorder'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.smiley')
                ->import(
                    $row['smilieid'],
                    $data,
                    ['fileLocation' => $row['smiliepath']]
                );
        }
    }

    /**
     * Returns the number of attachments.
     *
     * @param   integer     $indexName
     * @return  integer
     */
    private function countAttachments($indexName)
    {
        $sql = "SELECT	COUNT(*) AS count
			FROM	" . $this->databasePrefix . "attachments
			WHERE	" . $indexName . " > ?";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([0]);
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Exports attachments.
     *
     * @param   integer     $indexName
     * @param   string      $objectType
     * @param   integer     $offset
     * @param   integer     $limit
     */
    private function exportAttachments($indexName, $objectType, $offset, $limit)
    {
        $sql = "SELECT		*
			FROM		" . $this->databasePrefix . "attachments
			WHERE		" . $indexName . " > ?
			ORDER BY	attachmentid DESC";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute([0]);
        while ($row = $statement->fetchArray()) {
            $fileLocation = $this->fileSystemPath . 'attachments/attachment-' . $row['attachmentid'] . '.' . $row['attachmentextension'];

            $data = [
                'objectID' => $row[$indexName],
                'userID' => (!empty($row['userid']) ? $row['userid'] : null),
                'filename' => $row['attachmentname'] . '.' . $row['attachmentextension'],
                'downloads' => $row['counter'],
                'uploadTime' => (!empty($row['uploadtime']) ? $row['uploadtime'] : 0),
                'showOrder' => 0,
            ];

            ImportHandler::getInstance()
                ->getImporter($objectType)
                ->import(
                    $row['attachmentid'],
                    $data,
                    ['fileLocation' => $fileLocation]
                );
        }
    }

    /**
     * Returns message with BBCodes as used in WCF.
     *
     * @param   string      $text
     * @return  string
     */
    private static function fixBBCodes($text)
    {
        $text = \str_ireplace('[center]', '[align=center]', $text);
        $text = \str_ireplace('[/center]', '[/align]', $text);

        // remove crap
        return MessageUtil::stripCrap($text);
    }
}
