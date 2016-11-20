<?php
namespace wcf\system\exporter;
use wbb\data\board\Board;
use wcf\data\user\group\UserGroup;
use wcf\system\database\DatabaseException;
use wcf\system\importer\ImportHandler;
use wcf\system\request\LinkHandler;
use wcf\system\Regex;
use wcf\system\WCF;
use wcf\util\FileUtil;
use wcf\util\MessageUtil;
use wcf\util\PasswordUtil;
use wcf\util\StringUtil;
use wcf\util\UserRegistrationUtil;
use wcf\util\UserUtil;

/**
 * Exporter for vBulletin 5.x
 * 
 * @author	Tim Duesterhus
 * @copyright	2001-2016 WoltLab GmbH
 * @license	GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package	com.woltlab.wcf.exporter
 * @subpackage	system.exporter
 * @category	Community Framework
 */
class VB5xExporter extends AbstractExporter {
	const ATTACHFILE_DATABASE = 0;
	const ATTACHFILE_FILESYSTEM = 1;
	const ATTACHFILE_FILESYSTEM_SUBFOLDER = 2;
	
	/**
	 * board cache
	 * @var	array
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
		'com.woltlab.wbb.acl' => 50
	];
	
	/**
	 * @inheritDoc
	 */
	public function getSupportedData() {
		return [
			'com.woltlab.wcf.user' => [
				'com.woltlab.wcf.user.group',
				'com.woltlab.wcf.user.avatar',
			/*	'com.woltlab.wcf.user.option',*/
			/*	'com.woltlab.wcf.user.comment',
				'com.woltlab.wcf.user.follower',
				'com.woltlab.wcf.user.rank'*/
			],
			'com.woltlab.wbb.board' => [
				/*'com.woltlab.wbb.acl',*/
				'com.woltlab.wbb.attachment',
				'com.woltlab.wbb.poll',
			/*	'com.woltlab.wbb.watchedThread',
				'com.woltlab.wbb.like',
				'com.woltlab.wcf.label'*/
			],
		/*	'com.woltlab.wcf.conversation' => array(
				'com.woltlab.wcf.conversation.label'
			),
			'com.woltlab.wcf.smiley' => array()*/
		];
	}
	
	/**
	 * @inheritDoc
	 */
	public function validateDatabaseAccess() {
		parent::validateDatabaseAccess();
		
		$templateversion = $this->readOption('templateversion');
		
		if (version_compare($templateversion, '5.0.0', '<')) throw new DatabaseException('Cannot import less than vB 5.0.x', $this->database);
	}
	
	/**
	 * @inheritDoc
	 */
	public function validateFileAccess() {
		if (in_array('com.woltlab.wcf.smiley', $this->selectedData)) {
			if (empty($this->fileSystemPath) || !@file_exists($this->fileSystemPath . 'includes/version_vbulletin.php')) return false;
		}
		
		if (in_array('com.woltlab.wbb.attachment', $this->selectedData)) {
			if ($this->readOption('attachfile') != self::ATTACHFILE_DATABASE) {
				// TODO: Not yet supported
				return false;
			}
		}
		
		if (in_array('com.woltlab.wcf.user.avatar', $this->selectedData)) {
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
	public function getQueue() {
		$queue = [];
		
		// user
		if (in_array('com.woltlab.wcf.user', $this->selectedData)) {
			if (in_array('com.woltlab.wcf.user.group', $this->selectedData)) {
				$queue[] = 'com.woltlab.wcf.user.group';
			//	if (in_array('com.woltlab.wcf.user.rank', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.rank';
			}
			//if (in_array('com.woltlab.wcf.user.option', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.option';
			$queue[] = 'com.woltlab.wcf.user';
			if (in_array('com.woltlab.wcf.user.avatar', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.avatar';
			
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
		if (in_array('com.woltlab.wbb.board', $this->selectedData)) {
			$queue[] = 'com.woltlab.wbb.board';
		/*	if (in_array('com.woltlab.wcf.label', $this->selectedData)) $queue[] = 'com.woltlab.wcf.label'; */
			$queue[] = 'com.woltlab.wbb.thread';
			$queue[] = 'com.woltlab.wbb.post';
			
			/*if (in_array('com.woltlab.wbb.acl', $this->selectedData)) $queue[] = 'com.woltlab.wbb.acl';*/
			if (in_array('com.woltlab.wbb.attachment', $this->selectedData)) $queue[] = 'com.woltlab.wbb.attachment';
			/*if (in_array('com.woltlab.wbb.watchedThread', $this->selectedData)) $queue[] = 'com.woltlab.wbb.watchedThread';*/
			if (in_array('com.woltlab.wbb.poll', $this->selectedData)) {
				$queue[] = 'com.woltlab.wbb.poll';
				$queue[] = 'com.woltlab.wbb.poll.option';
				$queue[] = 'com.woltlab.wbb.poll.option.vote';
			}
		/*	if (in_array('com.woltlab.wbb.like', $this->selectedData)) $queue[] = 'com.woltlab.wbb.like';*/
		}
		
		// smiley
	/*	if (in_array('com.woltlab.wcf.smiley', $this->selectedData)) {
			$queue[] = 'com.woltlab.wcf.smiley.category';
			$queue[] = 'com.woltlab.wcf.smiley';
		}*/
		
		return $queue;
	}
	
	/**
	 * Counts user groups.
	 */
	public function countUserGroups() {
		return $this->__getMaxID($this->databasePrefix."usergroup", 'usergroupid');
	}
	
	/**
	 * Exports user groups.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportUserGroups($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."usergroup
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
				'userOnlineMarking' => $row['opentag'].'%s'.$row['closetag']
			]);
		}
	}
	

	/**
	 * Counts users.
	 */
	public function countUsers() {
		return $this->__getMaxID($this->databasePrefix."user", 'userid');
	}
	
	/**
	 * Exports users.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportUsers($offset, $limit) {
		// prepare password update
		$sql = "UPDATE	wcf".WCF_N."_user
			SET	password = ?
			WHERE	userID = ?";
		$passwordUpdateStatement = WCF::getDB()->prepareStatement($sql);
		
		// get users
		$sql = "SELECT		user_table.*, textfield.*, useractivation.type AS activationType, useractivation.emailchange, userban.liftdate, userban.reason AS banReason
			FROM		".$this->databasePrefix."user user_table
			LEFT JOIN	".$this->databasePrefix."usertextfield textfield
			ON		user_table.userid = textfield.userid
			LEFT JOIN	".$this->databasePrefix."useractivation useractivation
			ON		user_table.userid = useractivation.userid
			LEFT JOIN	".$this->databasePrefix."userban userban
			ON		user_table.userid = userban.userid
			WHERE		user_table.userid BETWEEN ? AND ?
			ORDER BY	user_table.userid";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			$data = [
				'username' => $row['username'],
				'password' => '',
				'email' => $row['email'],
				'registrationDate' => $row['joindate'],
				'banned' => $row['liftdate'] !== null && $row['liftdate'] == 0 ? 1 : 0,
				'banReason' => $row['banReason'],
				'activationCode' => $row['activationType'] !== null && $row['activationType'] == 0 && $row['emailchange'] == 0 ? UserRegistrationUtil::getActivationCode() : 0, // vB's codes are strings
				'oldUsername' => '',
				'registrationIpAddress' => UserUtil::convertIPv4To6($row['ipaddress']), // TODO: check whether this is the registration IP
				'signature' => $row['signature'],
				'userTitle' => ($row['customtitle'] != 0) ? $row['usertitle'] : '',
				'lastActivityTime' => $row['lastactivity']
			];
			$additionalData = [
				'groupIDs' => explode(',', $row['membergroupids'].','.$row['usergroupid']),
				'options' => []
			];
			
			// import user
			$newUserID = ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user')->import($row['userid'], $data, $additionalData);
			
			// update password hash
			if ($newUserID) {
				if (StringUtil::startsWith($row['scheme'], 'blowfish')) {
					$password = PasswordUtil::getSaltedHash($row['token'], $row['token']);
				}
				else if ($row['scheme'] == 'legacy') {
					$password = 'vb5:'.implode(':', explode(' ', $row['token'], 2));
				}
				
				$passwordUpdateStatement->execute([$password, $newUserID]);
			}
		}
	}
	
	/**
	 * Counts user avatars.
	 */
	public function countUserAvatars() {
		return $this->__getMaxID($this->databasePrefix."customavatar", 'userid');
	}
	
	/**
	 * Exports user avatars.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 * @throws	\Exception
	 */
	public function exportUserAvatars($offset, $limit) {
		$sql = "SELECT		customavatar.*, user.avatarrevision
			FROM		".$this->databasePrefix."customavatar customavatar
			LEFT JOIN	".$this->databasePrefix."user user
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
					if (!StringUtil::startsWith($file, '/')) $file = realpath($this->fileSystemPath.$file);
					$file = FileUtil::addTrailingSlash($file).'avatar'.$row['userid'].'_'.$row['avatarrevision'].'.gif';
				}
				else {
					$file = FileUtil::getTemporaryFilename('avatar_');
					file_put_contents($file, $row['filedata']);
				}
				
				ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.avatar')->import($row['userid'], [
					'avatarName' => $row['filename'],
					'avatarExtension' => pathinfo($row['filename'], PATHINFO_EXTENSION),
					'width' => $row['width'],
					'height' => $row['height'],
					'userID' => $row['userid']
				], ['fileLocation' => $file]);
				
				if (!$this->readOption('usefileavatar')) unlink($file);
			}
			catch (\Exception $e) {
				if (!$this->readOption('usefileavatar') && $file) @unlink($file);
				
				throw $e;
			}
		}
	}
	
	
	/**
	 * Counts boards.
	 */
	public function countBoards() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."node node
			
			INNER JOIN	(SELECT contenttypeid FROM ".$this->databasePrefix."contenttype WHERE class = ?) x
			ON		x.contenttypeid = node.contenttypeid";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(['Channel']);
		$row = $statement->fetchArray();
		return ($row['count'] ? 1 : 0);
	}
	
	/**
	 * Exports boards.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportBoards($offset, $limit) {
		$sql = "SELECT		node.*
			FROM		".$this->databasePrefix."node node
			
			INNER JOIN	(SELECT contenttypeid FROM ".$this->databasePrefix."contenttype WHERE class = ?) x
			ON		x.contenttypeid = node.contenttypeid
			
			ORDER BY	nodeid";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(['Channel']);
		while ($row = $statement->fetchArray()) {
			$this->boardCache[$row['parentid']][] = $row;
		}
		
		$this->exportBoardsRecursively();
	}
	
	/**
	 * Exports the boards recursively.
	 *
	 * @param	integer		$parentID
	 */
	protected function exportBoardsRecursively($parentID = 0) {
		if (!isset($this->boardCache[$parentID])) return;
		
		foreach ($this->boardCache[$parentID] as $board) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.board')->import($board['nodeid'], [
				'parentID' => $board['parentid'] ?: null,
				'position' => $board['displayorder'] ?: 0,
				'boardType' => Board::TYPE_BOARD,
				'title' => $board['title'],
				'description' => $board['description'],
				'descriptionUseHtml' => 0,
				'enableMarkingAsDone' => 0,
				'ignorable' => 1
			]);
			
			$this->exportBoardsRecursively($board['nodeid']);
		}
	}
	
	/**
	 * Counts threads.
	 */
	public function countThreads() {
		return $this->__getMaxID($this->databasePrefix."node", 'nodeid');
	}
	
	/**
	 * Exports threads.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportThreads($offset, $limit) {
		$sql = "SELECT		child.*, view.count AS views
			FROM		".$this->databasePrefix."node child
			INNER JOIN	".$this->databasePrefix."node parent
			ON		child.parentid = parent.nodeid
			LEFT JOIN	".$this->databasePrefix."nodeview view
			ON		child.nodeid = view.nodeid
			
			INNER JOIN	(SELECT contenttypeid FROM ".$this->databasePrefix."contenttype WHERE class = ?) x
			ON		x.contenttypeid = parent.contenttypeid
			INNER JOIN	(SELECT contenttypeid FROM ".$this->databasePrefix."contenttype WHERE class IN (?, ?)) y
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
				'username' => $row['authorname'],
				'views' => $row['views'] ?: 0,
				'isAnnouncement' => 0,
				'isSticky' => $row['sticky'],
				'isDisabled' => $row['approved'] ? 0 : 1,
				'isClosed' => $row['open'] ? 0 : 1,
				'isDeleted' => $row['deleteuserid'] !== null ? 1 : 0,
				'deleteTime' => $row['deleteuserid'] !== null ? TIME_NOW : 0
			];
			$additionalData = [];
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.thread')->import($row['nodeid'], $data, $additionalData);
		}
	}
	
	/**
	 * Counts posts.
	 */
	public function countPosts() {
		return $this->__getMaxID($this->databasePrefix."node", 'nodeid');
	}
	
	/**
	 * Exports posts.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportPosts($offset, $limit) {
		$sql = "SELECT		child.*, IF(parent.contenttypeid = child.contenttypeid, 0, 1) AS isFirstPost, text.*
			FROM		".$this->databasePrefix."node child
			INNER JOIN	".$this->databasePrefix."text text
			ON		child.nodeid = text.nodeid
			INNER JOIN	".$this->databasePrefix."node parent
			ON		child.parentid = parent.nodeid
			
			INNER JOIN	(SELECT contenttypeid FROM ".$this->databasePrefix."contenttype WHERE class IN(?, ?)) x
			ON		x.contenttypeid = child.contenttypeid
			
			WHERE		child.nodeid BETWEEN ? AND ?
			ORDER BY	child.nodeid ASC";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(['Text', 'Poll', $offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.post')->import($row['nodeid'], [
				'threadID' => $row['isFirstPost'] ? $row['nodeid'] : $row['parentid'],
				'userID' => $row['userid'],
				'username' => $row['authorname'],
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
				'ipAddress' => UserUtil::convertIPv4To6($row['ipaddress'])
			]);
		}
	}
	
	/**
	 * Counts post attachments.
	 */
	public function countPostAttachments() {
		return $this->__getMaxID($this->databasePrefix."node", 'nodeid');
	}
	
	/**
	 * Exports post attachments.
	 * 
	 * @param	integer		$offset
	 * @param	integer		$limit
	 * @throws	\Exception
	 */
	public function exportPostAttachments($offset, $limit) {
		$sql = "SELECT		child.*, attach.*, filedata.*
			FROM		".$this->databasePrefix."node child
			INNER JOIN	".$this->databasePrefix."node parent
			ON		child.parentid = parent.nodeid
			INNER JOIN	".$this->databasePrefix."node grandparent
			ON		parent.parentid = grandparent.nodeid
			INNER JOIN	".$this->databasePrefix."attach attach
			ON		child.nodeid = attach.nodeid
			INNER JOIN	".$this->databasePrefix."filedata filedata
			ON		attach.filedataid = filedata.filedataid
			
			INNER JOIN	(SELECT contenttypeid FROM ".$this->databasePrefix."contenttype WHERE class IN(?, ?, ?)) x
			ON		x.contenttypeid = grandparent.contenttypeid
			INNER JOIN	(SELECT contenttypeid FROM ".$this->databasePrefix."contenttype WHERE class = ?) y
			ON		y.contenttypeid = parent.contenttypeid
			INNER JOIN	(SELECT contenttypeid FROM ".$this->databasePrefix."contenttype WHERE class = ?) z
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
						file_put_contents($file, $row['filedata']);
					break;
				}
				
				// unable to read file -> abort
				if (!is_file($file) || !is_readable($file)) continue;
				if ($imageSize = @getimagesize($file)) {
					$row['isImage'] = 1;
					$row['width'] = $imageSize[0];
					$row['height'] = $imageSize[1];
				}
				else {
					$row['isImage'] = $row['width'] = $row['height'] = 0;
				}
				
				ImportHandler::getInstance()->getImporter('com.woltlab.wbb.attachment')->import($row['nodeid'], [
					'objectID' => $row['parentid'],
					'userID' => $row['userid'] ?: null,
					'filename' => $row['filename'],
					'filesize' => isset($row['filesize']) ? $row['filesize'] : filesize($file),
					'fileType' => FileUtil::getMimeType($file),
					'isImage' => $row['isImage'],
					'width' => $row['width'],
					'height' => $row['height'],
					'downloads' => $row['counter'],
					'uploadTime' => $row['dateline'],
					'showOrder' => isset($row['displayOrder']) ? $row['displayOrder'] : 0
				], ['fileLocation' => $file]);
				
				if ($this->readOption('attachfile') == self::ATTACHFILE_DATABASE) unlink($file);
			}
			catch (\Exception $e) {
				if ($this->readOption('attachfile') == self::ATTACHFILE_DATABASE && $file) @unlink($file);
			
				throw $e;
			}
		}
	}
	
	/**
	 * Counts polls.
	 */
	public function countPolls() {
		return $this->__getMaxID($this->databasePrefix."poll", 'pollid');
	}
	
	/**
	 * Exports polls.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportPolls($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."poll
			WHERE		pollid BETWEEN ? AND ?
			ORDER BY	pollid";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll')->import($row['pollid'], [
				'objectID' => $row['nodeid'],
				'question' => $row['question'],
				'time' => $row['dateline'],
				'endTime' => $row['dateline'] + $row['timeout'] * 86400,
				'isChangeable' => 0,
				'isPublic' => $row['public'] ? 1 : 0,
				'sortByVotes' => 0,
				'maxVotes' => $row['multiple'] ? $row['numberoptions'] : 1,
				'votes' => $row['votes']
			]);
		}
	}
	
	/**
	 * Counts poll options.
	 */
	public function countPollOptions() {
		return $this->__getMaxID($this->databasePrefix."polloption", 'polloptionid');
	}
	
	/**
	 * Exports poll options.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportPollOptions($offset, $limit) {
		$sql = "SELECT		polloption.*, poll.pollid
			FROM		".$this->databasePrefix."polloption polloption
			LEFT JOIN	".$this->databasePrefix."poll poll
			ON		poll.nodeid = polloption.nodeid
			WHERE		polloption.polloptionid BETWEEN ? AND ?
			ORDER BY	polloption.polloptionid";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll.option')->import($row['polloptionid'], [
				'pollID' => $row['pollid'],
				'optionValue' => $row['title'],
				'votes' => $row['votes']
			]);
		}
	}
	
	
	/**
	 * Counts poll option votes.
	 */
	public function countPollOptionVotes() {
		return $this->__getMaxID($this->databasePrefix."pollvote", 'pollvoteid');
	}
	
	/**
	 * Exports poll option votes.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportPollOptionVotes($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."pollvote
			WHERE		pollvoteid BETWEEN ? AND ?
			ORDER BY	pollvoteid";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll.option.vote')->import(0, [
				'pollID' => $row['pollid'],
				'optionID' => $row['polloptionid'],
				'userID' => $row['userid']
			]);
		}
	}
	
	/**
	 * Returns the value of the given option in the imported board.
	 * 
	 * @param	string		$optionName
	 * @return	mixed
	 */
	private function readOption($optionName) {
		static $optionCache = [];
		
		if (!isset($optionCache[$optionName])) {
			$sql = "SELECT	value
				FROM	".$this->databasePrefix."setting
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
	 * @param	string		$message
	 * @return	string
	 */
	private static function fixBBCodes($message) {
		static $quoteRegex = null;
		static $quoteCallback = null;
		static $imgRegex = null;
		static $mediaRegex = null;
		
		if ($quoteRegex === null) {
			$quoteRegex = new Regex('\[quote=(.*?);n(\d+)\]', Regex::CASE_INSENSITIVE);
			$quoteCallback = function ($matches) {
				$username = str_replace(["\\", "'"], ["\\\\", "\'"], $matches[1]);
				$postID = $matches[2];
				
				$postLink = LinkHandler::getInstance()->getLink('Thread', [
						'application' => 'wbb',
						'postID' => $postID,
						'forceFrontend' => true
					]).'#post'.$postID;
				$postLink = str_replace(["\\", "'"], ["\\\\", "\'"], $postLink);
				
				return "[quote='".$username."','".$postLink."']";
			};
			
			$imgRegex = new Regex('\[img width=(\d+) height=\d+\](.*?)\[/img\]');
			$mediaRegex = new Regex('\[video=([a-z]+);([a-z0-9-_]+)\]', Regex::CASE_INSENSITIVE);
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
			'[/video]' => '[/media]'
		];
		$message = str_ireplace(array_keys($replacements), array_values($replacements), $message);
		
		// quotes
		$message = $quoteRegex->replace($message, $quoteCallback);
		
		// img
		$message = $imgRegex->replace($message, "[img='\\2',none,\\1][/img]");
		
		// fix size bbcodes
		$message = preg_replace_callback('/\[size=\'?(\d+)\'?\]/i', function ($matches) {
			$size = 36;
			
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
			}
			
			return '[size='.$size.']';
		}, $message);
		
		// media
		$message = $mediaRegex->replace($message, '[media]');
		
		$message = MessageUtil::stripCrap($message);
		
		return $message;
	}
}
