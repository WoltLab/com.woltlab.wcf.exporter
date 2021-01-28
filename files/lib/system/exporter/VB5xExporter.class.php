<?php

namespace wcf\system\exporter;

use gallery\data\album\Album;
use wbb\data\board\Board;
use wcf\data\user\group\UserGroup;
use wcf\data\user\option\UserOption;
use wcf\system\database\DatabaseException;
use wcf\system\exception\SystemException;
use wcf\system\importer\ImportHandler;
use wcf\system\Regex;
use wcf\system\request\LinkHandler;
use wcf\system\WCF;
use wcf\util\FileUtil;
use wcf\util\JSON;
use wcf\util\MessageUtil;
use wcf\util\StringUtil;
use wcf\util\UserRegistrationUtil;
use wcf\util\UserUtil;

/**
 * Exporter for vBulletin 5.x
 *
 * @author  Tim Duesterhus
 * @copyright   2001-2019 WoltLab GmbH
 * @license GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package WoltLabSuite\Core\System\Exporter
 */
class VB5xExporter extends AbstractExporter
{
    const CHANNELOPTIONS_CANCONTAINTHREADS = 4;

    const ATTACHFILE_DATABASE = 0;

    const ATTACHFILE_FILESYSTEM = 1;

    const ATTACHFILE_FILESYSTEM_SUBFOLDER = 2;

    /**
     * board cache
     * @var array
     */
    protected $boardCache = [];

    protected $blogCache = [];

    /**
     * @inheritDoc
     */
    protected $methods = [
        'com.woltlab.wcf.user' => 'Users',
        'com.woltlab.wcf.user.group' => 'UserGroups',
        'com.woltlab.wcf.user.rank' => 'UserRanks',
        'com.woltlab.wcf.user.follower' => 'Followers',
        'com.woltlab.wcf.user.comment' => 'GuestbookEntries',
        'com.woltlab.wcf.user.comment.response' => 'GuestbookResponses',
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
        'com.woltlab.wbb.like' => 'Likes',
        'com.woltlab.wcf.label' => 'Labels',
        'com.woltlab.wbb.acl' => 'ACLs',
        'com.woltlab.wcf.smiley.category' => 'SmileyCategories',
        'com.woltlab.wcf.smiley' => 'Smilies',

        'com.woltlab.blog.blog' => 'Blogs',
        'com.woltlab.blog.category' => 'BlogCategories',
        'com.woltlab.blog.entry' => 'BlogEntries',
        'com.woltlab.blog.entry.attachment' => 'BlogAttachments',
        'com.woltlab.blog.entry.comment' => 'BlogComments',
        'com.woltlab.blog.entry.comment.response' => 'BlogCommentResponses',
        'com.woltlab.blog.entry.like' => 'BlogEntryLikes',

        'com.woltlab.gallery.category' => 'GalleryCategories',
        'com.woltlab.gallery.album' => 'GalleryAlbums',
        'com.woltlab.gallery.image' => 'GalleryImages',
        'com.woltlab.gallery.image.comment' => 'GalleryComments',
        'com.woltlab.gallery.image.comment.response' => 'GalleryCommentResponses',
        'com.woltlab.gallery.image.like' => 'GalleryImageLikes',
        'com.woltlab.gallery.image.marker' => 'GalleryImageMarkers',
    ];

    /**
     * @inheritDoc
     */
    protected $limits = [
        'com.woltlab.wcf.user' => 100,
        'com.woltlab.wcf.user.avatar' => 100,
        'com.woltlab.wcf.conversation.attachment' => 100,
        'com.woltlab.wbb.thread' => 200,
        'com.woltlab.wbb.attachment' => 100,
        'com.woltlab.wbb.acl' => 50,
        'com.woltlab.blog.entry.attachment' => 100,
        'com.woltlab.gallery.image' => 50,
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
                /*  'com.woltlab.wcf.user.comment',
                'com.woltlab.wcf.user.follower',
                'com.woltlab.wcf.user.rank'*/
            ],
            'com.woltlab.wbb.board' => [
                /*'com.woltlab.wbb.acl',*/
                'com.woltlab.wbb.attachment',
                'com.woltlab.wbb.poll',
                /*  'com.woltlab.wbb.watchedThread',
                'com.woltlab.wbb.like',
                'com.woltlab.wcf.label'*/
            ],
            /*  'com.woltlab.wcf.conversation' => array(
                'com.woltlab.wcf.conversation.label'
            ),*/
            'com.woltlab.wcf.smiley' => [],

            'com.woltlab.blog.entry' => [
                /*  'com.woltlab.blog.category',*/
                'com.woltlab.blog.entry.attachment',
                'com.woltlab.blog.entry.comment',
                /*  'com.woltlab.blog.entry.like'*/
            ],

            'com.woltlab.gallery.image' => [
                /*  'com.woltlab.gallery.category',*/
                'com.woltlab.gallery.album',
                /*  'com.woltlab.gallery.image.comment',
                'com.woltlab.gallery.image.like',
                'com.woltlab.gallery.image.marker'*/
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function validateDatabaseAccess()
    {
        parent::validateDatabaseAccess();

        $templateversion = $this->readOption('templateversion');

        if (\version_compare($templateversion, '5.0.0', '<')) {
            throw new DatabaseException('Cannot import less than vB 5.0.x', $this->database);
        }
    }

    /**
     * @inheritDoc
     */
    public function validateFileAccess()
    {
        if (\in_array('com.woltlab.wcf.smiley', $this->selectedData)) {
            if (empty($this->fileSystemPath) || !@\file_exists($this->fileSystemPath . 'includes/version_vbulletin.php')) {
                return false;
            }
        }

        if (\in_array('com.woltlab.wbb.attachment', $this->selectedData)) {
            if ($this->readOption('attachfile') != self::ATTACHFILE_DATABASE) {
                // TODO: Not yet supported
                return false;
            }
        }

        if (\in_array('com.woltlab.wcf.user.avatar', $this->selectedData)) {
            if ($this->readOption('usefileavatar')) {
                // TODO: Not yet supported
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
                //  if (in_array('com.woltlab.wcf.user.rank', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.rank';
            }
            if (\in_array('com.woltlab.wcf.user.option', $this->selectedData)) {
                $queue[] = 'com.woltlab.wcf.user.option';
            }
            $queue[] = 'com.woltlab.wcf.user';
            if (\in_array('com.woltlab.wcf.user.avatar', $this->selectedData)) {
                $queue[] = 'com.woltlab.wcf.user.avatar';
            }

            /*if (in_array('com.woltlab.wcf.user.comment', $this->selectedData)) {
                $queue[] = 'com.woltlab.wcf.user.comment';
            }

            if (in_array('com.woltlab.wcf.user.follower', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.follower';

            // conversation
            if (in_array('com.woltlab.wcf.conversation', $this->selectedData)) {
                if (in_array('com.woltlab.wcf.conversation.label', $this->selectedData)) $queue[] = 'com.woltlab.wcf.conversation.label';

                $queue[] = 'com.woltlab.wcf.conversation';
                $queue[] = 'com.woltlab.wcf.conversation.message';
                $queue[] = 'com.woltlab.wcf.conversation.user';
            }*/
        }

        // board
        if (\in_array('com.woltlab.wbb.board', $this->selectedData)) {
            $queue[] = 'com.woltlab.wbb.board';
            /*  if (in_array('com.woltlab.wcf.label', $this->selectedData)) $queue[] = 'com.woltlab.wcf.label'; */
            $queue[] = 'com.woltlab.wbb.thread';
            $queue[] = 'com.woltlab.wbb.post';

            /*if (in_array('com.woltlab.wbb.acl', $this->selectedData)) $queue[] = 'com.woltlab.wbb.acl';*/
            if (\in_array('com.woltlab.wbb.attachment', $this->selectedData)) {
                $queue[] = 'com.woltlab.wbb.attachment';
            }
            /*if (in_array('com.woltlab.wbb.watchedThread', $this->selectedData)) $queue[] = 'com.woltlab.wbb.watchedThread';*/
            if (\in_array('com.woltlab.wbb.poll', $this->selectedData)) {
                $queue[] = 'com.woltlab.wbb.poll';
                $queue[] = 'com.woltlab.wbb.poll.option';
                $queue[] = 'com.woltlab.wbb.poll.option.vote';
            }
            /*  if (in_array('com.woltlab.wbb.like', $this->selectedData)) $queue[] = 'com.woltlab.wbb.like';*/
        }

        // blog
        if (\in_array('com.woltlab.blog.entry', $this->selectedData)) {
            $queue[] = 'com.woltlab.blog.blog';
            /*  if (in_array('com.woltlab.blog.category', $this->selectedData)) $queue[] = 'com.woltlab.blog.category';*/
            $queue[] = 'com.woltlab.blog.entry';
            if (\in_array('com.woltlab.blog.entry.attachment', $this->selectedData)) {
                $queue[] = 'com.woltlab.blog.entry.attachment';
            }
            if (\in_array('com.woltlab.blog.entry.comment', $this->selectedData)) {
                $queue[] = 'com.woltlab.blog.entry.comment';
                /*      $queue[] = 'com.woltlab.blog.entry.comment.response';*/
            }
            /*  if (in_array('com.woltlab.blog.entry.like', $this->selectedData)) $queue[] = 'com.woltlab.blog.entry.like';*/
        }

        // gallery
        if (\in_array('com.woltlab.gallery.image', $this->selectedData)) {
            /*  if (in_array('com.woltlab.gallery.category', $this->selectedData)) $queue[] = 'com.woltlab.gallery.category';*/
            if (\in_array('com.woltlab.gallery.album', $this->selectedData)) {
                $queue[] = 'com.woltlab.gallery.album';
            }
            $queue[] = 'com.woltlab.gallery.image';
            /*  if (in_array('com.woltlab.gallery.image.comment', $this->selectedData)) {
                    $queue[] = 'com.woltlab.gallery.image.comment';
                    $queue[] = 'com.woltlab.gallery.image.comment.response';
                }
                if (in_array('com.woltlab.gallery.image.like', $this->selectedData)) $queue[] = 'com.woltlab.gallery.image.like';
                if (in_array('com.woltlab.gallery.image.marker', $this->selectedData)) $queue[] = 'com.woltlab.gallery.image.marker';*/
        }

        // smiley
        if (\in_array('com.woltlab.wcf.smiley', $this->selectedData)) {
            $queue[] = 'com.woltlab.wcf.smiley.category';
            $queue[] = 'com.woltlab.wcf.smiley';
        }

        return $queue;
    }

    /**
     * Counts user groups.
     */
    public function countUserGroups()
    {
        return $this->__getMaxID($this->databasePrefix . "usergroup", 'usergroupid');
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
			FROM		" . $this->databasePrefix . "usergroup
			WHERE		usergroupid BETWEEN ? AND ?
			ORDER BY	usergroupid";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            switch ($row['systemgroupid']) {
                case 1:
                    $groupType = UserGroup::GUESTS;
                    break;
                case 2:
                    $groupType = UserGroup::USERS;
                    break;
                default:
                    $groupType = UserGroup::OTHER;
                    break;
            }

            ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.group')->import($row['usergroupid'], [
                'groupName' => $row['title'],
                'groupDescription' => $row['description'],
                'groupType' => $groupType,
                'userOnlineMarking' => $row['opentag'] . '%s' . $row['closetag'],
            ]);
        }
    }

    /**
     * Counts users.
     */
    public function countUsers()
    {
        return $this->__getMaxID($this->databasePrefix . "user", 'userid');
    }

    /**
     * Exports users.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportUsers($offset, $limit)
    {
        // cache user options
        $userOptions = [];
        $sql = "SELECT	*
			FROM	" . $this->databasePrefix . "profilefield";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            if ($row['type'] == 'select_multiple' || $row['type'] == 'checkbox') {
                $row['data'] = @\unserialize($row['data']);
            }

            $userOptions[] = $row;
        }

        // prepare password update
        $sql = "UPDATE	wcf" . WCF_N . "_user
			SET	password = ?
			WHERE	userID = ?";
        $passwordUpdateStatement = WCF::getDB()->prepareStatement($sql);

        // get users
        $sql = "SELECT		userfield.*, user_table.*, textfield.*, useractivation.type AS activationType, useractivation.emailchange, userban.liftdate, userban.reason AS banReason
			FROM		" . $this->databasePrefix . "user user_table
			LEFT JOIN	" . $this->databasePrefix . "usertextfield textfield
			ON		user_table.userid = textfield.userid
			LEFT JOIN	" . $this->databasePrefix . "useractivation useractivation
			ON		user_table.userid = useractivation.userid
			LEFT JOIN	" . $this->databasePrefix . "userban userban
			ON		user_table.userid = userban.userid
			LEFT JOIN	" . $this->databasePrefix . "userfield userfield
			ON		userfield.userid = user_table.userid
			WHERE		user_table.userid BETWEEN ? AND ?
			ORDER BY	user_table.userid";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'username' => $row['username'],
                'password' => null,
                'email' => $row['email'],
                'registrationDate' => $row['joindate'],
                'banned' => $row['liftdate'] !== null && $row['liftdate'] == 0 ? 1 : 0,
                'banReason' => $row['banReason'],
                'activationCode' => $row['activationType'] !== null && $row['activationType'] == 0 && $row['emailchange'] == 0 ? UserRegistrationUtil::getActivationCode() : 0, // vB's codes are strings
                'oldUsername' => '',
                'registrationIpAddress' => UserUtil::convertIPv4To6($row['ipaddress']), // TODO: check whether this is the registration IP
                'signature' => self::fixBBCodes($row['signature']),
                'userTitle' => ($row['customtitle'] != 0) ? $row['usertitle'] : '',
                'lastActivityTime' => $row['lastactivity'],
            ];

            $options = [];
            if ($row['birthday']) {
                $options['birthday'] = self::convertBirthday($row['birthday']);
            }

            $additionalData = [
                'groupIDs' => \explode(',', $row['membergroupids'] . ',' . $row['usergroupid']),
                'options' => $options,
            ];

            // handle user options
            foreach ($userOptions as $userOption) {
                $optionID = $userOption['profilefieldid'];
                if (isset($row['field' . $optionID])) {
                    $userOptionValue = $row['field' . $optionID];
                    if ($userOptionValue && ($userOption['type'] == 'select_multiple' || $userOption['type'] == 'checkbox')) {
                        if (\is_array($userOption['data'])) {
                            $newUserOptionValue = '';
                            foreach ($userOption['data'] as $key => $value) {
                                if ($userOptionValue & 2 ** $key) {
                                    if (!empty($newUserOptionValue)) {
                                        $newUserOptionValue .= "\n";
                                    }
                                    $newUserOptionValue .= $value;
                                }
                            }
                            $userOptionValue = $newUserOptionValue;
                        }
                    }

                    $additionalData['options'][$optionID] = $userOptionValue;
                }
            }

            // import user
            $newUserID = ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user')->import($row['userid'], $data, $additionalData);

            // update password hash
            if ($newUserID) {
                if (StringUtil::startsWith($row['scheme'], 'blowfish')) {
                    $password = 'Bcrypt:' . $row['token'];
                } elseif (StringUtil::startsWith($row['scheme'], 'argon2')) {
                    $password = 'argon2:' . $row['token'];
                } elseif ($row['scheme'] == 'legacy') {
                    $password = 'vb5:' . \implode(':', \explode(' ', $row['token'], 2));
                } else {
                    continue;
                }

                $passwordUpdateStatement->execute([$password, $newUserID]);
            }
        }
    }

    /**
     * Counts user avatars.
     */
    public function countUserAvatars()
    {
        return $this->__getMaxID($this->databasePrefix . "customavatar", 'userid');
    }

    /**
     * Exports user avatars.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     * @throws  \Exception
     */
    public function exportUserAvatars($offset, $limit)
    {
        $sql = "SELECT		customavatar.*, user.avatarrevision
			FROM		" . $this->databasePrefix . "customavatar customavatar
			LEFT JOIN	" . $this->databasePrefix . "user user
			ON		user.userid = customavatar.userid
			WHERE		customavatar.userid BETWEEN ? AND ?
			ORDER BY	customavatar.userid";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $file = null;

            try {
                // TODO: not yet supported
                if (false && $this->readOption('usefileavatar')) {
                    $file = $this->readOption('avatarpath');
                    if (!StringUtil::startsWith($file, '/')) {
                        $file = \realpath($this->fileSystemPath . $file);
                    }
                    $file = FileUtil::addTrailingSlash($file) . 'avatar' . $row['userid'] . '_' . $row['avatarrevision'] . '.gif';
                } else {
                    $file = FileUtil::getTemporaryFilename('avatar_');
                    \file_put_contents($file, $row['filedata']);
                }

                ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.avatar')->import($row['userid'], [
                    'avatarName' => $row['filename'],
                    'avatarExtension' => \pathinfo($row['filename'], \PATHINFO_EXTENSION),
                    'width' => $row['width'],
                    'height' => $row['height'],
                    'userID' => $row['userid'],
                ], ['fileLocation' => $file]);

                if (!$this->readOption('usefileavatar')) {
                    \unlink($file);
                }
            } catch (\Exception $e) {
                if (!$this->readOption('usefileavatar') && $file) {
                    @\unlink($file);
                }

                throw $e;
            }
        }
    }

    /**
     * Counts user options.
     */
    public function countUserOptions()
    {
        $sql = "SELECT	COUNT(*) AS count
			FROM	" . $this->databasePrefix . "profilefield";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute();
        $row = $statement->fetchArray();

        return $row['count'] ? 1 : 0;
    }

    /**
     * Exports user options.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportUserOptions($offset, $limit)
    {
        $sql = "SELECT	*
			FROM	" . $this->databasePrefix . "profilefield";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $editable = 0;
            switch ($row['editable']) {
                case 0:
                    $editable = UserOption::EDITABILITY_ADMINISTRATOR;
                    break;
                case 1:
                case 2:
                    $editable = UserOption::EDITABILITY_ALL;
                    break;
            }

            $visible = UserOption::VISIBILITY_ALL;
            if ($row['hidden']) {
                $visible = UserOption::VISIBILITY_ADMINISTRATOR;
            }

            // get select options
            $selectOptions = [];
            if ($row['type'] == 'radio' || $row['type'] == 'select' || $row['type'] == 'select_multiple' || $row['type'] == 'checkbox') {
                $selectOptions = @\unserialize($row['data']);

                if (!\is_array($selectOptions)) {
                    $selectOptions = @\unserialize(\mb_convert_encoding($row['data'], 'ISO-8859-1', 'UTF-8'));
                    if (!\is_array($selectOptions)) {
                        continue;
                    }

                    $selectOptions = \array_map(static function ($item) {
                        return \mb_convert_encoding($item, 'UTF-8', 'ISO-8859-1');
                    }, $selectOptions);
                }
            }

            // get option type
            $optionType = 'text';
            switch ($row['type']) {
                case 'textarea':
                    $optionType = 'textarea';
                    break;
                case 'radio':
                    $optionType = 'radioButton';
                    break;
                case 'select':
                    $optionType = 'select';
                    break;
                case 'select_multiple':
                case 'checkbox':
                    $optionType = 'multiSelect';
                    break;
            }

            // get default value
            $defaultValue = '';
            switch ($row['type']) {
                case 'input':
                case 'textarea':
                    $defaultValue = $row['data'];
                    break;
                case 'radio':
                case 'select':
                    if ($row['def']) {
                        // use first radio option
                        $defaultValue = \reset($selectOptions);
                    }
                    break;
            }

            // get required status
            $required = $askDuringRegistration = 0;
            switch ($row['required']) {
                case 1:
                case 3:
                    $required = 1;
                    break;
                case 2:
                    $askDuringRegistration = 1;
                    break;
            }

            // get field name
            $fieldName = 'field' . $row['profilefieldid'];
            $sql = "SELECT	text
				FROM	" . $this->databasePrefix . "phrase
				WHERE	languageid = ?
					AND varname = ?";
            $statement2 = $this->database->prepareStatement($sql);
            $statement2->execute([0, 'field' . $row['profilefieldid'] . '_title']);
            $row2 = $statement2->fetchArray();
            if ($row2 !== false) {
                $fieldName = $row2['text'];
            }

            ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.option')->import($row['profilefieldid'], [
                'categoryName' => 'profile.personal',
                'optionType' => $optionType,
                'defaultValue' => $defaultValue,
                'validationPattern' => $row['regex'],
                'selectOptions' => \implode("\n", $selectOptions),
                'required' => $required,
                'askDuringRegistration' => $askDuringRegistration,
                'searchable' => $row['searchable'],
                'editable' => $editable,
                'visible' => $visible,
                'showOrder' => $row['displayorder'],
            ], ['name' => $fieldName]);
        }
    }

    /**
     * Counts boards.
     */
    public function countBoards()
    {
        $sql = "SELECT	COUNT(*) AS count
			FROM	" . $this->databasePrefix . "node node
			
			INNER JOIN	(SELECT contenttypeid FROM " . $this->databasePrefix . "contenttype WHERE class = ?) x
			ON		x.contenttypeid = node.contenttypeid";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute(['Channel']);
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
        $sql = "SELECT		node.*, channel.guid, channel.options AS channelOptions
			FROM		" . $this->databasePrefix . "node node
			
			INNER JOIN	(SELECT contenttypeid FROM " . $this->databasePrefix . "contenttype WHERE class = ?) x
			ON		x.contenttypeid = node.contenttypeid
			
			INNER JOIN	" . $this->databasePrefix . "channel channel
			ON		channel.nodeid = node.nodeid
			
			ORDER BY	parentid, displayorder";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute(['Channel']);

        $boardRoot = 0;
        while ($row = $statement->fetchArray()) {
            $this->boardCache[$row['parentid']][] = $row;
            if ($row['guid'] === 'vbulletin-4ecbdf567f2c35.70389590') {
                $boardRoot = $row['nodeid'];
            }
        }

        if ($boardRoot !== 0) {
            // Pretend that the subforums of the boardRoot do not have a parent board.
            foreach ($this->boardCache[$boardRoot] as $board) {
                $board['parentid'] = 0;
            }
        }

        $this->exportBoardsRecursively($boardRoot);
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
            ImportHandler::getInstance()->getImporter('com.woltlab.wbb.board')->import($board['nodeid'], [
                'parentID' => $board['parentid'] ?: null,
                'position' => $board['displayorder'] ?: 0,
                'boardType' => $board['channelOptions'] & self::CHANNELOPTIONS_CANCONTAINTHREADS ? Board::TYPE_BOARD : Board::TYPE_CATEGORY,
                'title' => $board['title'],
                'description' => $board['description'],
                'descriptionUseHtml' => 0,
                'enableMarkingAsDone' => 0,
                'ignorable' => 1,
            ]);

            $this->exportBoardsRecursively($board['nodeid']);
        }
    }

    /**
     * Counts threads.
     */
    public function countThreads()
    {
        return $this->__getMaxID($this->databasePrefix . "node", 'nodeid');
    }

    /**
     * Exports threads.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportThreads($offset, $limit)
    {
        $sql = "SELECT		child.*, view.count AS views
			FROM		" . $this->databasePrefix . "node child
			INNER JOIN	" . $this->databasePrefix . "node parent
			ON		child.parentid = parent.nodeid
			LEFT JOIN	" . $this->databasePrefix . "nodeview view
			ON		child.nodeid = view.nodeid
			
			INNER JOIN	(SELECT contenttypeid FROM " . $this->databasePrefix . "contenttype WHERE class = ?) x
			ON		x.contenttypeid = parent.contenttypeid
			INNER JOIN	(SELECT contenttypeid FROM " . $this->databasePrefix . "contenttype WHERE class IN (?, ?)) y
			ON		y.contenttypeid = child.contenttypeid
			
			WHERE		child.nodeid BETWEEN ? AND ?
			ORDER BY	child.nodeid ASC";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute(['Channel', 'Text', 'Poll', $offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'boardID' => $row['parentid'],
                'topic' => StringUtil::decodeHTML($row['title']),
                'time' => $row['created'],
                'userID' => $row['userid'],
                'username' => $row['authorname'] ?: '',
                'views' => $row['views'] ?: 0,
                'isAnnouncement' => 0,
                'isSticky' => $row['sticky'],
                'isDisabled' => $row['approved'] ? 0 : 1,
                'isClosed' => $row['open'] ? 0 : 1,
                'isDeleted' => $row['deleteuserid'] !== null ? 1 : 0,
                'deleteTime' => $row['deleteuserid'] !== null ? TIME_NOW : 0,
            ];
            $additionalData = [];

            ImportHandler::getInstance()->getImporter('com.woltlab.wbb.thread')->import($row['nodeid'], $data, $additionalData);
        }
    }

    /**
     * Counts posts.
     */
    public function countPosts()
    {
        return $this->__getMaxID($this->databasePrefix . "node", 'nodeid');
    }

    /**
     * Exports posts.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportPosts($offset, $limit)
    {
        $sql = "SELECT		child.*, IF(parent.contenttypeid = child.contenttypeid, 0, 1) AS isFirstPost, text.*
			FROM		" . $this->databasePrefix . "node child
			INNER JOIN	" . $this->databasePrefix . "text text
			ON		child.nodeid = text.nodeid
			INNER JOIN	" . $this->databasePrefix . "node parent
			ON		child.parentid = parent.nodeid
			
			INNER JOIN	(SELECT contenttypeid FROM " . $this->databasePrefix . "contenttype WHERE class IN(?, ?)) x
			ON		x.contenttypeid = child.contenttypeid
			
			WHERE		child.nodeid BETWEEN ? AND ?
			ORDER BY	child.nodeid ASC";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute(['Text', 'Poll', $offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            ImportHandler::getInstance()->getImporter('com.woltlab.wbb.post')->import($row['nodeid'], [
                'threadID' => $row['isFirstPost'] ? $row['nodeid'] : $row['parentid'],
                'userID' => $row['userid'],
                'username' => $row['authorname'] ?: '',
                'subject' => StringUtil::decodeHTML($row['title']),
                'message' => self::fixBBCodes($row['rawtext']),
                'time' => $row['created'],
                'isDeleted' => $row['deleteuserid'] !== null ? 1 : 0,
                'deleteTime' => $row['deleteuserid'] !== null ? TIME_NOW : 0,
                'isDisabled' => $row['approved'] ? 0 : 1,
                'isClosed' => 0,
                'editorID' => null, // TODO
                'editor' => '',
                'lastEditTime' => 0,
                'editCount' => 0,
                'editReason' => '',
                'enableHtml' => (isset($row['htmlState']) && $row['htmlState'] != 'off') ? 1 : 0,
                'ipAddress' => UserUtil::convertIPv4To6($row['ipaddress']),
            ]);
        }
    }

    /**
     * Counts post attachments.
     */
    public function countPostAttachments()
    {
        return $this->__getMaxID($this->databasePrefix . "node", 'nodeid');
    }

    /**
     * Exports post attachments.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     * @throws  \Exception
     */
    public function exportPostAttachments($offset, $limit)
    {
        $sql = "SELECT		child.*, attach.*, filedata.*
			FROM		" . $this->databasePrefix . "node child
			INNER JOIN	" . $this->databasePrefix . "node parent
			ON		child.parentid = parent.nodeid
			INNER JOIN	" . $this->databasePrefix . "node grandparent
			ON		parent.parentid = grandparent.nodeid
			INNER JOIN	" . $this->databasePrefix . "attach attach
			ON		child.nodeid = attach.nodeid
			INNER JOIN	" . $this->databasePrefix . "filedata filedata
			ON		attach.filedataid = filedata.filedataid
			
			INNER JOIN	(SELECT contenttypeid FROM " . $this->databasePrefix . "contenttype WHERE class IN(?, ?, ?)) x
			ON		x.contenttypeid = grandparent.contenttypeid
			INNER JOIN	(SELECT contenttypeid FROM " . $this->databasePrefix . "contenttype WHERE class = ?) y
			ON		y.contenttypeid = parent.contenttypeid
			INNER JOIN	(SELECT contenttypeid FROM " . $this->databasePrefix . "contenttype WHERE class = ?) z
			ON		z.contenttypeid = child.contenttypeid
			
			WHERE		child.nodeid BETWEEN ? AND ?
			ORDER BY	child.nodeid ASC";
        $statement = $this->database->prepareStatement($sql);

        // Text in a Text or Poll should be a post
        // Text in a Channel should be a thread
        $statement->execute(['Text', 'Poll', 'Channel', 'Text', 'Attach', $offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $file = null;

            try {
                switch ($this->readOption('attachfile')) {
                    case self::ATTACHFILE_DATABASE:
                        $file = FileUtil::getTemporaryFilename('attachment_');
                        \file_put_contents($file, $row['filedata']);
                        break;
                }

                // unable to read file -> abort
                if (!\is_file($file) || !\is_readable($file)) {
                    continue;
                }

                ImportHandler::getInstance()->getImporter('com.woltlab.wbb.attachment')->import($row['nodeid'], [
                    'objectID' => $row['parentid'],
                    'userID' => $row['userid'] ?: null,
                    'filename' => $row['filename'],
                    'downloads' => $row['counter'],
                    'uploadTime' => $row['dateline'],
                    'showOrder' => $row['displayOrder'] ?? 0,
                ], ['fileLocation' => $file]);

                if ($this->readOption('attachfile') == self::ATTACHFILE_DATABASE) {
                    \unlink($file);
                }
            } catch (\Exception $e) {
                if ($this->readOption('attachfile') == self::ATTACHFILE_DATABASE && $file) {
                    @\unlink($file);
                }

                throw $e;
            }
        }
    }

    /**
     * Counts polls.
     */
    public function countPolls()
    {
        return $this->__getMaxID($this->databasePrefix . "poll", 'nodeid');
    }

    /**
     * Exports polls.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportPolls($offset, $limit)
    {
        $sql = "SELECT		poll.*, node.title, node.created
			FROM		" . $this->databasePrefix . "poll poll
			INNER JOIN	" . $this->databasePrefix . "node node
			ON		poll.nodeid = node.nodeid
			WHERE		poll.nodeid BETWEEN ? AND ?
			ORDER BY	poll.nodeid";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll')->import($row['nodeid'], [
                'objectID' => $row['nodeid'],
                'question' => $row['title'],
                'time' => $row['created'],
                'endTime' => $row['created'] + $row['timeout'] * 86400,
                'isChangeable' => 0,
                'isPublic' => $row['public'] ? 1 : 0,
                'sortByVotes' => 0,
                'maxVotes' => $row['multiple'] ? $row['numberoptions'] : 1,
                'votes' => $row['votes'],
            ]);
        }
    }

    /**
     * Counts poll options.
     */
    public function countPollOptions()
    {
        return $this->__getMaxID($this->databasePrefix . "polloption", 'polloptionid');
    }

    /**
     * Exports poll options.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportPollOptions($offset, $limit)
    {
        $sql = "SELECT		polloption.*, poll.nodeid
			FROM		" . $this->databasePrefix . "polloption polloption
			LEFT JOIN	" . $this->databasePrefix . "poll poll
			ON		poll.nodeid = polloption.nodeid
			WHERE		polloption.polloptionid BETWEEN ? AND ?
			ORDER BY	polloption.polloptionid";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll.option')->import($row['polloptionid'], [
                'pollID' => $row['nodeid'],
                'optionValue' => $row['title'],
                'votes' => $row['votes'],
            ]);
        }
    }

    /**
     * Counts poll option votes.
     */
    public function countPollOptionVotes()
    {
        return $this->__getMaxID($this->databasePrefix . "pollvote", 'pollvoteid');
    }

    /**
     * Exports poll option votes.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportPollOptionVotes($offset, $limit)
    {
        $sql = "SELECT		*
			FROM		" . $this->databasePrefix . "pollvote
			WHERE		pollvoteid BETWEEN ? AND ?
			ORDER BY	pollvoteid";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll.option.vote')->import(0, [
                'pollID' => $row['nodeid'],
                'optionID' => $row['polloptionid'],
                'userID' => $row['userid'],
            ]);
        }
    }

    /**
     * Counts blogs.
     */
    public function countBlogs()
    {
        $sql = "SELECT	COUNT(*) AS count
			FROM	" . $this->databasePrefix . "node node
			
			INNER JOIN	(SELECT contenttypeid FROM " . $this->databasePrefix . "contenttype WHERE class = ?) x
			ON		x.contenttypeid = node.contenttypeid";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute(['Channel']);
        $row = $statement->fetchArray();

        return $row['count'] ? 1 : 0;
    }

    /**
     * Exports blogs.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportBlogs($offset, $limit)
    {
        $sql = "SELECT		node.*, channel.guid
			FROM		" . $this->databasePrefix . "node node
			
			INNER JOIN	(SELECT contenttypeid FROM " . $this->databasePrefix . "contenttype WHERE class = ?) x
			ON		x.contenttypeid = node.contenttypeid
			
			INNER JOIN	" . $this->databasePrefix . "channel channel
			ON		channel.nodeid = node.nodeid
			
			ORDER BY	nodeid";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute(['Channel']);

        $blogRoot = 0;
        while ($row = $statement->fetchArray()) {
            $this->blogCache[$row['parentid']][] = $row;
            if ($row['guid'] === 'vbulletin-4ecbdf567f3a38.99555305') {
                $blogRoot = $row['nodeid'];
            }
        }

        // If the blog root could not be found then we skip, because we don't want to import boards as blogs.
        if ($blogRoot === 0) {
            return;
        }

        $this->exportBlogsRecursively($blogRoot);
    }

    /**
     * Exports the blogs recursively.
     *
     * @param   integer     $parentID
     */
    protected function exportBlogsRecursively($parentID = 0)
    {
        if (!isset($this->blogCache[$parentID])) {
            return;
        }

        foreach ($this->blogCache[$parentID] as $blog) {
            ImportHandler::getInstance()->getImporter('com.woltlab.blog.blog')->import($blog['nodeid'], [
                'userID' => $blog['userid'],
                'username' => $blog['authorname'] ?: '',
                'title' => $blog['title'],
                'description' => $blog['description'],
            ]);

            $this->exportBlogsRecursively($blog['nodeid']);
        }
    }

    /**
     * Counts blog entries.
     */
    public function countBlogEntries()
    {
        return $this->__getMaxID($this->databasePrefix . "node", 'nodeid');
    }

    /**
     * Exports blog entries.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportBlogEntries($offset, $limit)
    {
        $sql = "SELECT		child.*, view.count AS views, text.*
			FROM		" . $this->databasePrefix . "node child
			INNER JOIN	" . $this->databasePrefix . "node parent
			ON		child.parentid = parent.nodeid
			LEFT JOIN	" . $this->databasePrefix . "nodeview view
			ON		child.nodeid = view.nodeid
			INNER JOIN	" . $this->databasePrefix . "text text
			ON		child.nodeid = text.nodeid
			
			INNER JOIN	(SELECT contenttypeid FROM " . $this->databasePrefix . "contenttype WHERE class = ?) x
			ON		x.contenttypeid = parent.contenttypeid
			INNER JOIN	(SELECT contenttypeid FROM " . $this->databasePrefix . "contenttype WHERE class IN (?)) y
			ON		y.contenttypeid = child.contenttypeid
			
			WHERE		child.nodeid BETWEEN ? AND ?
			ORDER BY	child.nodeid ASC";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute(['Channel', 'Text', $offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            // The importer will create blogs on demand. As we cannot specifically filter out blogs within MySQL
            // we need to check whether the parentid matches a valid blog.
            if (!ImportHandler::getInstance()->getNewID('com.woltlab.blog.blog', $row['parentid'])) {
                continue;
            }

            $additionalData = [];

            $data = [
                'userID' => $row['userid'],
                'username' => $row['authorname'] ?: '',
                'subject' => StringUtil::decodeHTML($row['title']),
                'message' => self::fixBBCodes($row['rawtext']),
                'time' => $row['created'],
                'views' => $row['views'] ?: 0,
                'enableHtml' => (isset($row['htmlState']) && $row['htmlState'] != 'off') ? 1 : 0,
                'ipAddress' => UserUtil::convertIPv4To6($row['ipaddress']),
                'blogID' => $row['parentid'],
            ];

            ImportHandler::getInstance()->getImporter('com.woltlab.blog.entry')->import($row['nodeid'], $data, $additionalData);
        }
    }

    /**
     * Counts blog attachments.
     */
    public function countBlogAttachments()
    {
        return $this->__getMaxID($this->databasePrefix . "node", 'nodeid');
    }

    /**
     * Exports blog attachments.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportBlogAttachments($offset, $limit)
    {
        $sql = "SELECT		child.*, attach.*, filedata.*
			FROM		" . $this->databasePrefix . "node child
			INNER JOIN	" . $this->databasePrefix . "node parent
			ON		child.parentid = parent.nodeid
			INNER JOIN	" . $this->databasePrefix . "node grandparent
			ON		parent.parentid = grandparent.nodeid
			INNER JOIN	" . $this->databasePrefix . "attach attach
			ON		child.nodeid = attach.nodeid
			INNER JOIN	" . $this->databasePrefix . "filedata filedata
			ON		attach.filedataid = filedata.filedataid
			
			INNER JOIN	(SELECT contenttypeid FROM " . $this->databasePrefix . "contenttype WHERE class IN(?)) x
			ON		x.contenttypeid = grandparent.contenttypeid
			INNER JOIN	(SELECT contenttypeid FROM " . $this->databasePrefix . "contenttype WHERE class = ?) y
			ON		y.contenttypeid = parent.contenttypeid
			INNER JOIN	(SELECT contenttypeid FROM " . $this->databasePrefix . "contenttype WHERE class = ?) z
			ON		z.contenttypeid = child.contenttypeid
			
			WHERE		child.nodeid BETWEEN ? AND ?
			ORDER BY	child.nodeid ASC";
        $statement = $this->database->prepareStatement($sql);

        $statement->execute(['Channel', 'Text', 'Attach', $offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $file = null;

            try {
                switch ($this->readOption('attachfile')) {
                    case self::ATTACHFILE_DATABASE:
                        $file = FileUtil::getTemporaryFilename('attachment_');
                        \file_put_contents($file, $row['filedata']);
                        break;
                }

                // unable to read file -> abort
                if (!\is_file($file) || !\is_readable($file)) {
                    continue;
                }

                ImportHandler::getInstance()->getImporter('com.woltlab.blog.entry.attachment')->import($row['nodeid'], [
                    'objectID' => $row['parentid'],
                    'userID' => $row['userid'] ?: null,
                    'filename' => $row['filename'],
                    'downloads' => $row['counter'],
                    'uploadTime' => $row['dateline'],
                    'showOrder' => $row['displayOrder'] ?? 0,
                ], ['fileLocation' => $file]);

                if ($this->readOption('attachfile') == self::ATTACHFILE_DATABASE) {
                    \unlink($file);
                }
            } catch (\Exception $e) {
                if ($this->readOption('attachfile') == self::ATTACHFILE_DATABASE && $file) {
                    @\unlink($file);
                }

                throw $e;
            }
        }
    }

    /**
     * Counts blog comments.
     */
    public function countBlogComments()
    {
        return $this->__getMaxID($this->databasePrefix . "node", 'nodeid');
    }

    /**
     * Exports blog comments.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportBlogComments($offset, $limit)
    {
        $sql = "SELECT		child.*, text.*
			FROM		" . $this->databasePrefix . "node child
			INNER JOIN	" . $this->databasePrefix . "node parent
			ON		child.parentid = parent.nodeid
			INNER JOIN	" . $this->databasePrefix . "text text
			ON		child.nodeid = text.nodeid
			
			INNER JOIN	(SELECT contenttypeid FROM " . $this->databasePrefix . "contenttype WHERE class = ?) x
			ON		x.contenttypeid = parent.contenttypeid
			INNER JOIN	(SELECT contenttypeid FROM " . $this->databasePrefix . "contenttype WHERE class IN (?)) y
			ON		y.contenttypeid = child.contenttypeid
			
			WHERE		child.nodeid BETWEEN ? AND ?
			ORDER BY	child.nodeid ASC";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute(['Text', 'Text', $offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            ImportHandler::getInstance()->getImporter('com.woltlab.blog.entry.comment')->import($row['nodeid'], [
                'objectID' => $row['parentid'],
                'userID' => $row['userid'] ?: null,
                'username' => $row['authorname'] ?: '',
                'message' => self::fixBBCodes($row['rawtext']),
                'time' => $row['created'],
            ]);
        }
    }

    /**
     * Counts gallery albums.
     */
    public function countGalleryAlbums()
    {
        return $this->__getMaxID($this->databasePrefix . "node", 'nodeid');
    }

    /**
     * Exports gallery albums.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportGalleryAlbums($offset, $limit)
    {
        $sql = "SELECT		node.*
			FROM		" . $this->databasePrefix . "node node
			
			INNER JOIN	(SELECT contenttypeid FROM " . $this->databasePrefix . "contenttype WHERE class = ?) x
			ON		x.contenttypeid = node.contenttypeid
			
			WHERE		node.nodeid BETWEEN ? AND ?
			ORDER BY	node.nodeid ASC";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute(['Gallery', $offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'userID' => $row['userid'],
                'username' => $row['authorname'] ?: '',
                'title' => $row['title'],
                'description' => $row['description'],
                'lastUpdateTime' => $row['lastcontent'],
                'accessLevel' => Album::ACCESS_EVERYONE, // TODO: Check whether this is sane.
            ];

            ImportHandler::getInstance()->getImporter('com.woltlab.gallery.album')->import($row['nodeid'], $data);
        }
    }

    /**
     * Counts gallery images.
     */
    public function countGalleryImages()
    {
        return $this->__getMaxID($this->databasePrefix . "node", 'nodeid');
    }

    /**
     * Exports gallery images.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportGalleryImages($offset, $limit)
    {
        $sql = "SELECT		child.*, photo.*, filedata.*
			FROM		" . $this->databasePrefix . "node child
			INNER JOIN	" . $this->databasePrefix . "node parent
			ON		child.parentid = parent.nodeid
			INNER JOIN	" . $this->databasePrefix . "photo photo
			ON		child.nodeid = photo.nodeid
			INNER JOIN	" . $this->databasePrefix . "filedata filedata
			ON		photo.filedataid = filedata.filedataid
			
			INNER JOIN	(SELECT contenttypeid FROM " . $this->databasePrefix . "contenttype WHERE class = ?) x
			ON		x.contenttypeid = parent.contenttypeid
			INNER JOIN	(SELECT contenttypeid FROM " . $this->databasePrefix . "contenttype WHERE class IN (?)) y
			ON		y.contenttypeid = child.contenttypeid
			
			WHERE		child.nodeid BETWEEN ? AND ?
			ORDER BY	child.nodeid ASC";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute(['Gallery', 'Photo', $offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $file = null;

            try {
                switch ($this->readOption('attachfile')) {
                    case self::ATTACHFILE_DATABASE:
                        $file = FileUtil::getTemporaryFilename('attachment_');
                        \file_put_contents($file, $row['filedata']);
                        break;
                }

                // unable to read file -> abort
                if (!\is_file($file) || !\is_readable($file)) {
                    continue;
                }

                ImportHandler::getInstance()->getImporter('com.woltlab.gallery.image')->import($row['nodeid'], [
                    'userID' => $row['userid'],
                    'username' => $row['authorname'] ?: '',
                    'albumID' => $row['parentid'],
                    'title' => $row['title'],
                    'description' => ($row['title'] != $row['caption'] ? $row['caption'] : ''),
                    'uploadTime' => $row['created'],
                ], ['fileLocation' => $file]);
            } finally {
                if ($this->readOption('attachfile') == self::ATTACHFILE_DATABASE && $file) {
                    @\unlink($file);
                }
            }
        }
    }

    /**
     * Counts smilies.
     */
    public function countSmilies()
    {
        $sql = "SELECT	COUNT(*) AS count
			FROM	" . $this->databasePrefix . "smilie";
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
			FROM		" . $this->databasePrefix . "smilie
			ORDER BY	smilieid";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $fileLocation = $this->fileSystemPath . $row['smiliepath'];

            ImportHandler::getInstance()->getImporter('com.woltlab.wcf.smiley')->import($row['smilieid'], [
                'smileyTitle' => $row['title'],
                'smileyCode' => $row['smilietext'],
                'showOrder' => $row['displayorder'],
                'categoryID' => !empty($row['imagecategoryid']) ? $row['imagecategoryid'] : null,
            ], ['fileLocation' => $fileLocation]);
        }
    }

    /**
     * Counts smiley categories.
     */
    public function countSmileyCategories()
    {
        $sql = "SELECT	COUNT(*) AS count
			FROM	" . $this->databasePrefix . "imagecategory
			WHERE	imagetype = ?";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([3]);
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Exports smiley categories.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportSmileyCategories($offset, $limit)
    {
        $sql = "SELECT		*
			FROM		" . $this->databasePrefix . "imagecategory
			WHERE		imagetype = ?
			ORDER BY	imagecategoryid";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute([3]);
        while ($row = $statement->fetchArray()) {
            ImportHandler::getInstance()->getImporter('com.woltlab.wcf.smiley.category')->import($row['imagecategoryid'], [
                'title' => $row['title'],
                'parentCategoryID' => 0,
                'showOrder' => $row['displayorder'],
            ]);
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
            $sql = "SELECT	value
				FROM	" . $this->databasePrefix . "setting
				WHERE	varname = ?";
            $statement = $this->database->prepareStatement($sql);
            $statement->execute([$optionName]);
            $row = $statement->fetchArray();

            $optionCache[$optionName] = $row['value'];
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
        static $quoteRegex = null;
        static $quoteCallback = null;
        static $urlRegex = null;
        static $urlCallback = null;
        static $imgRegex = null;
        static $mediaRegex = null;
        static $img2Regex = null;
        static $img2Callback = null;
        static $attachRegex = null;
        static $attachCallback = null;
        static $tableRegex = null;

        if ($quoteRegex === null) {
            $quoteRegex = new Regex('\[quote=(.*?);n(\d+)\]', Regex::CASE_INSENSITIVE);
            $quoteCallback = static function ($matches) {
                $username = \str_replace(["\\", "'"], ["\\\\", "\\'"], $matches[1]);
                $postID = $matches[2];

                $postLink = LinkHandler::getInstance()->getLink('Thread', [
                    'application' => 'wbb',
                    'postID' => $postID,
                    'forceFrontend' => true,
                ]) . '#post' . $postID;
                $postLink = \str_replace(["\\", "'"], ["\\\\", "\\'"], $postLink);

                return "[quote='" . $username . "','" . $postLink . "']";
            };

            $urlRegex = new Regex('\[url="([^"]+)"\]', Regex::CASE_INSENSITIVE);
            $urlCallback = static function ($matches) {
                $url = \str_replace(["\\", "'"], ["\\\\", "\\'"], $matches[1]);

                return "[url='" . $url . "']";
            };

            $imgRegex = new Regex('\[img width=(\d+) height=\d+\](.*?)\[/img\]');
            $mediaRegex = new Regex('\[video=([a-z]+);([a-z0-9-_]+)\]', Regex::CASE_INSENSITIVE);

            $img2Regex = new Regex('\[img2=json\](.*?)\[/img2\]', Regex::CASE_INSENSITIVE);
            $img2Callback = static function ($matches) {
                if (!empty($matches[1])) {
                    // json
                    try {
                        $payload = JSON::decode($matches[1]);
                    } catch (SystemException $e) {
                        return $matches[0];
                    }

                    if (isset($payload['src'])) {
                        return "[img]" . $payload['src'] . "[/img]";
                    }
                }

                return $matches[0];
            };

            $attachRegex = new Regex('\[attach=(?:json\](\{.*?\})|config\]([0-9]+))\[/attach\]', Regex::CASE_INSENSITIVE);
            $attachCallback = static function ($matches) {
                if (!empty($matches[1])) {
                    // json
                    try {
                        $payload = JSON::decode($matches[1]);
                    } catch (SystemException $e) {
                        return '';
                    }

                    if (empty($payload['data-attachmentid'])) {
                        return '';
                    }

                    return "[attach]" . $payload['data-attachmentid'] . "[/attach]";
                } elseif (!empty($matches[2])) {
                    // config
                    return "[attach]" . $matches[2] . "[/attach]";
                } else {
                    // technically unreachable
                    return "";
                }
            };

            $tableRegex = new Regex('\[TABLE(?:="[a-z0-9_-]+:\s*[a-z0-9_-]+(?:,\s*[a-z0-9_-]+:\s*[a-z0-9_-]+)*")?\]', Regex::CASE_INSENSITIVE);
        }

        // use proper WCF 2 bbcode
        $replacements = [
            '[left]' => '[align=left]',
            '[/left]' => '[/align]',
            '[right]' => '[align=right]',
            '[/right]' => '[/align]',
            '[center]' => '[align=center]',
            '[/center]' => '[/align]',
            '[php]' => '[code=php]',
            '[/php]' => '[/code]',
            '[html]' => '[code=html]',
            '[/html]' => '[/code]',
            '[/video]' => '[/media]',
        ];
        $message = \str_ireplace(\array_keys($replacements), \array_values($replacements), $message);

        // quotes
        $message = $quoteRegex->replace($message, $quoteCallback);

        // url
        $message = $urlRegex->replace($message, $urlCallback);

        // img
        $message = $imgRegex->replace($message, "[img='\\2',none,\\1][/img]");
        $message = $img2Regex->replace($message, $img2Callback);

        // attach
        $message = $attachRegex->replace($message, $attachCallback);

        // tables
        $message = $tableRegex->replace($message, '[table]');

        // fix size bbcodes
        $message = \preg_replace_callback('/\[size=\'?(\d+)(px)?\'?\]/i', static function ($matches) {
            $unit = 'scalar';
            if (!empty($matches[2])) {
                $unit = $matches[2];
            }

            $validSizes = [8, 10, 12, 14, 18, 24, 36];
            $size = 36;
            switch ($unit) {
                case 'px':
                    foreach ($validSizes as $pt) {
                        // 1 Point equals roughly 4/3 Pixels
                        if ($pt >= ($matches[1] / 4 * 3)) {
                            $size = $pt;
                            break;
                        }
                    }
                    break;
                case 'scalar':
                default:
                    if ($matches[1] >= 1 && $matches[1] <= 6) {
                        $size = $validSizes[$matches[1] - 1];
                    }
                    break;
            }

            return '[size=' . $size . ']';
        }, $message);

        // media
        $message = $mediaRegex->replace($message, '[media]');

        $message = MessageUtil::stripCrap($message);

        return $message;
    }

    /**
     * Converts vb's birthday format (mm-dd-yy)
     *
     * @param       string          $birthday
     * @return      string
     */
    private static function convertBirthday($birthday)
    {
        $a = \explode('-', $birthday);
        if (\count($a) != 3) {
            return '0000-00-00';
        }

        return $a[2] . '-' . $a[0] . '-' . $a[1];
    }
}
