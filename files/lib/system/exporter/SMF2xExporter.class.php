<?php
namespace wcf\system\exporter;
use wbb\data\board\Board;
use wbb\data\board\BoardCache;
use wcf\data\like\Like;
use wcf\data\object\type\ObjectTypeCache;
use wcf\data\user\group\UserGroup;
use wcf\data\user\option\UserOption;
use wcf\data\user\rank\UserRank;
use wcf\data\user\UserProfile;
use wcf\system\database\util\PreparedStatementConditionBuilder;
use wcf\system\database\DatabaseException;
use wcf\system\exception\SystemException;
use wcf\system\importer\ImportHandler;
use wcf\system\request\LinkHandler;
use wcf\system\Callback;
use wcf\system\Regex;
use wcf\system\WCF;
use wcf\util\ArrayUtil;
use wcf\util\FileUtil;
use wcf\util\StringUtil;
use wcf\util\UserRegistrationUtil;
use wcf\util\UserUtil;

/**
 * Exporter for SMF 2.x
 *
 * @author	Tim Duesterhus
 * @copyright	2001-2013 WoltLab GmbH
 * @license	WoltLab Burning Board License <http://www.woltlab.com/products/burning_board/license.php>
 * @package	com.woltlab.wcf.exporter
 * @subpackage	system.exporter
 * @category	Community Framework (commercial)
 */
class SMF2xExporter extends AbstractExporter {
	/**
	 * board cache
	 * @var array
	 */
	protected $boardCache = array();
	
	/**
	 * @see wcf\system\exporter\AbstractExporter::$methods
	 */
	protected $methods = array(
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
		'com.woltlab.wbb.like' => 'Likes',
		'com.woltlab.wcf.label' => 'Labels',
		'com.woltlab.wbb.acl' => 'ACLs',
		'com.woltlab.wcf.smiley' => 'Smilies'
	);
	
	/**
	 * @see wcf\system\exporter\AbstractExporter::$limits
	 */
	protected $limits = array(
		'com.woltlab.wcf.user' => 200,
		'com.woltlab.wcf.user.avatar' => 100,
		'com.woltlab.wcf.user.follower' => 100
	);
	
	/**
	 * @see wcf\system\exporter\IExporter::getSupportedData()
	 */
	public function getSupportedData() {
		return array(
			'com.woltlab.wcf.user' => array(
				'com.woltlab.wcf.user.group',
				'com.woltlab.wcf.user.avatar',
			/*	'com.woltlab.wcf.user.option',*/
				'com.woltlab.wcf.user.follower',
				'com.woltlab.wcf.user.rank'
			),
			'com.woltlab.wbb.board' => array(
				/*'com.woltlab.wbb.acl',*/
				'com.woltlab.wbb.attachment',
				'com.woltlab.wbb.poll',
				'com.woltlab.wbb.watchedThread'
			),
			'com.woltlab.wcf.conversation' => array(
				'com.woltlab.wcf.conversation.label'
			),
			'com.woltlab.wcf.smiley' => array()
		);
	}
	
	/**
	 * @see wcf\system\exporter\IExporter::validateDatabaseAccess()
	 */
	public function validateDatabaseAccess() {
		parent::validateDatabaseAccess();
		
		if (version_compare($this->readOption('smfVersion'), '2.0.0', '<=')) throw new DatabaseException('Cannot import less than SMF 2.x', $this->database);
	}
	
	/**
	 * @see wcf\system\exporter\IExporter::validateFileAccess()
	 */
	public function validateFileAccess() {
		if (in_array('com.woltlab.wcf.user.avatar', $this->selectedData) || in_array('com.woltlab.wbb.attachment', $this->selectedData) || in_array('com.woltlab.wcf.smiley', $this->selectedData)) {
			if (empty($this->fileSystemPath) || !@file_exists($this->fileSystemPath . 'SSI.php')) return false;
		}
		
		return true;
	}
	
	/**
	 * @see wcf\system\exporter\IExporter::getQueue()
	 */
	public function getQueue() {
		$queue = array();
		
		// user
		if (in_array('com.woltlab.wcf.user', $this->selectedData)) {
			if (in_array('com.woltlab.wcf.user.group', $this->selectedData)) {
				$queue[] = 'com.woltlab.wcf.user.group';
				if (in_array('com.woltlab.wcf.user.rank', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.rank';
			}
			
			/*if (in_array('com.woltlab.wcf.user.option', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.option';*/
			$queue[] = 'com.woltlab.wcf.user';
			if (in_array('com.woltlab.wcf.user.avatar', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.avatar';
			
			if (in_array('com.woltlab.wcf.user.follower', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.follower';
			
			// conversation
			if (in_array('com.woltlab.wcf.conversation', $this->selectedData)) {
				if (in_array('com.woltlab.wcf.conversation.label', $this->selectedData)) $queue[] = 'com.woltlab.wcf.conversation.label';
				
				$queue[] = 'com.woltlab.wcf.conversation';
				$queue[] = 'com.woltlab.wcf.conversation.message';
				$queue[] = 'com.woltlab.wcf.conversation.user';
			}
		}
		
		// board
		if (in_array('com.woltlab.wbb.board', $this->selectedData)) {
			$queue[] = 'com.woltlab.wbb.board';
			$queue[] = 'com.woltlab.wbb.thread';
			$queue[] = 'com.woltlab.wbb.post';
			
			/*if (in_array('com.woltlab.wbb.acl', $this->selectedData)) $queue[] = 'com.woltlab.wbb.acl';*/
			if (in_array('com.woltlab.wbb.attachment', $this->selectedData)) $queue[] = 'com.woltlab.wbb.attachment';
			if (in_array('com.woltlab.wbb.watchedThread', $this->selectedData)) $queue[] = 'com.woltlab.wbb.watchedThread';
			if (in_array('com.woltlab.wbb.poll', $this->selectedData)) {
				$queue[] = 'com.woltlab.wbb.poll';
				$queue[] = 'com.woltlab.wbb.poll.option';
				$queue[] = 'com.woltlab.wbb.poll.option.vote';
			}
		}
		
		// smiley
		if (in_array('com.woltlab.wcf.smiley', $this->selectedData)) $queue[] = 'com.woltlab.wcf.smiley';
		
		return $queue;
	}
	
	/**
	 * @see wcf\system\exporter\IExporter::getDefaultDatabasePrefix()
	 */
	public function getDefaultDatabasePrefix() {
		return 'smf_';
	}
	
	/**
	 * Counts user groups.
	 */
	public function countUserGroups() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."membergroups";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports user groups.
	 */
	public function exportUserGroups($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."membergroups
			ORDER BY	id_group ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.group')->import($row['id_group'], array(
				'groupName' => $row['group_name'],
				'groupType' => UserGroup::OTHER,
				'userOnlineMarking' => '<span style="color: '.$row['online_color'].';">%s</span>',
			));
		}
	}
	
	/**
	 * Counts users.
	 */
	public function countUsers() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."members";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports users.
	 */
	public function exportUsers($offset, $limit) {
		// prepare password update
		$sql = "UPDATE	wcf".WCF_N."_user
			SET	password = ?
			WHERE	userID = ?";
		$passwordUpdateStatement = WCF::getDB()->prepareStatement($sql);
		
		// get users
		$sql = "SELECT		member.*, ban_group.ban_time, ban_group.expire_time AS banExpire, ban_group.reason AS banReason
			FROM		".$this->databasePrefix."members member
			LEFT JOIN	".$this->databasePrefix."ban_items ban_item
			ON		(member.id_member = ban_item.id_member)
			LEFT JOIN	".$this->databasePrefix."ban_groups ban_group
			ON		(ban_item.id_ban_group = ban_group.id_ban_group)
			ORDER BY	member.id_member ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		
		while ($row = $statement->fetchArray()) {
			$data = array(
				'username' => $row['member_name'],
				'password' => '',
				'email' => $row['email_address'],
				'registrationDate' => $row['date_registered'],
				'banned' => ($row['ban_time'] && $row['banExpire'] === null ? 1 : 0), // only permabans are imported
				'banReason' => $row['banReason'],
				'activationCode' => $row['validation_code'] ? UserRegistrationUtil::getActivationCode() : 0, // smf's codes are strings
				'registrationIpAddress' => $row['member_ip'], // member_ip2 is HTTP_X_FORWARDED_FOR
				'signature' => $row['signature'],
				'signatureEnableBBCodes' => 1,
				'signatureEnableHtml' => 0,
				'signatureEnableSmilies' => 1,
				'userTitle' => $row['usertitle'],
				'lastActivityTime' => $row['last_login']
			);
			$additionalData = array(
				'groupIDs' => explode(',', $row['additional_groups'].','.$row['id_group']),
				'options' => array()
			);
			
			// import user
			$newUserID = ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user')->import($row['id_member'], $data, $additionalData);
			
			// update password hash
			if ($newUserID) {
				$passwordUpdateStatement->execute(array('smf2:'.$row['passwd'].':'.$row['password_salt'], $newUserID));
			}
		}
	}

	/**
	 * Counts user ranks.
	 */
	public function countUserRanks() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."membergroups
			WHERE	min_posts <> ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(-1));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports user ranks.
	 */
	public function exportUserRanks($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."membergroups
			WHERE		min_posts <> ?
			ORDER BY	id_group";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(-1));
		while ($row = $statement->fetchArray()) {
			list($repeatImage, $rankImage) = explode('#', $row['stars'], 2);
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.rank')->import($row['id_group'], array(
				'groupID' => $row['id_group'],
				'requiredPoints' => $row['min_posts'] * 5,
				'rankTitle' => $row['group_name'],
				'rankImage' => $rankImage,
				'repeatImage' => $repeatImage
			));
		}
	}
	
	/**
	 * Counts followers.
	 */
	public function countFollowers() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."members
			WHERE	buddy_list <> ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(''));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports followers.
	 */
	public function exportFollowers($offset, $limit) {
		$sql = "SELECT		id_member, buddy_list
			FROM		".$this->databasePrefix."members
			WHERE		buddy_list <> ?
			ORDER BY	id_member";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(''));
		while ($row = $statement->fetchArray()) {
			$buddylist = array_unique(ArrayUtil::toIntegerArray(explode(',', $row['buddy_list'])));
			
			foreach ($buddylist as $buddy) {
				ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.follower')->import(0, array(
					'userID' => $row['id_member'],
					'followUserID' => $buddy
				));
			}
		}
	}

	/**
	 * Counts user avatars.
	 */
	public function countUserAvatars() {
		$sql = "SELECT	(SELECT COUNT(*) AS count FROM ".$this->databasePrefix."attachments WHERE id_member <> ?)
				+ (SELECT COUNT(*) AS count FROM ".$this->databasePrefix."members WHERE avatar <> ?) AS count";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array('', 0));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports user avatars.
	 */
	public function exportUserAvatars($offset, $limit) {
		$sql = "(
				SELECT		id_member, 'attachment' AS type, filename AS avatarName, (id_attach || '_' || file_hash) AS filename
				FROM		".$this->databasePrefix."attachments
				WHERE		id_member <> ?
			)
			UNION
			(
				SELECT		id_member, 'user' AS type, avatar AS avatarName, avatar AS filename
				FROM		".$this->databasePrefix."members
				WHERE		avatar <> ?
			)";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array('', 0));
		
		while ($row = $statement->fetchArray()) {
			switch ($row['type']) {
				case 'attachment':
					$fileLocation = $this->readOption('attachmentUploadDir').'/'.$row['filename'];
				break;
				case 'user':
					if (FileUtil::isURL($row['filename'])) return;
					$fileLocation = $this->readOption('avatar_directory').'/'.$row['filename'];
				break;
			}
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.avatar')->import(0, array(
				'avatarName' => basename($row['avatarName']),
				'avatarExtension' => pathinfo($row['avatarName'], PATHINFO_EXTENSION),
				'userID' => $row['id_member']
			), array('fileLocation' => $fileLocation));
		}
	}
	
	/**
	 * Counts conversation folders.
	 */
	public function countConversationFolders() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."members
			WHERE	message_labels <> ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(''));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports conversation folders.
	 */
	public function exportConversationFolders($offset, $limit) {
		$sql = "SELECT		id_member, message_labels
			FROM		".$this->databasePrefix."members
			WHERE		message_labels <> ?
			ORDER BY	id_member";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(''));
		while ($row = $statement->fetchArray()) {
			$labels = ArrayUtil::trim(explode(',', $row['message_labels']), false);
			
			$i = 0;
			foreach ($labels as $label) {
				ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.label')->import($row['id_member'].'-'.$i++, array(
					'userID' => $row['id_member'],
					'label' => mb_substr($label, 0, 80)
				));
			}
		}
	}
	
	/**
	 * Counts conversations.
	 */
	public function countConversations() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."personal_messages
			WHERE	id_pm = id_pm_head";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports conversations.
	 */
	public function exportConversations($offset, $limit) {
		$sql = "INSERT IGNORE INTO	wcf".WCF_N."_conversation_to_user
						(conversationID, participantID, hideConversation, isInvisible, lastVisitTime)
			VALUES			(?, ?, ?, ?, ?)";
		$insertStatement = WCF::getDB()->prepareStatement($sql);
		
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."personal_messages
			WHERE		id_pm = id_pm_head
			ORDER BY	id_pm";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(0));
		while ($row = $statement->fetchArray()) {
			$conversationID = ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation')->import($row['id_pm'], array(
				'subject' => $row['subject'],
				'time' => $row['msgtime'],
				'userID' => $row['id_member_from'],
				'username' => $row['from_name'],
				'isDraft' => 0
			));
			
			// add author
			$insertStatement->execute(array(
				$conversationID,
				ImportHandler::getInstance()->getNewID('com.woltlab.wcf.user', $row['id_member_from']),
				0,
				0,
				TIME_NOW
			));
		}
	}
	
	/**
	 * Counts conversation messages.
	 */
	public function countConversationMessages() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."personal_messages";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports conversation messages.
	 */
	public function exportConversationMessages($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."personal_messages
			ORDER BY	id_pm";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.message')->import($row['id_pm'], array(
				'conversationID' => $row['id_pm_head'],
				'userID' => $row['id_member_from'],
				'username' => $row['from_name'],
				'message' => self::fixBBCodes($row['body']),
				'time' => $row['msgtime'],
				'attachments' => 0, // not supported
				'enableSmilies' => 1,
				'enableHtml' => 0,
				'enableBBCodes' => 1,
				'showSignature' => 1
			));
		}
	}
	
	/**
	 * Counts conversation recipients.
	 */
	public function countConversationUsers() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."pm_recipients";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports conversation recipients.
	 */
	public function exportConversationUsers($offset, $limit) {
		$sql = "SELECT		recipients.*, pm.id_pm_head, members.member_name
			FROM		".$this->databasePrefix."pm_recipients recipients
			LEFT JOIN	".$this->databasePrefix."personal_messages pm
			ON		(pm.id_pm = recipients.id_pm)
			LEFT JOIN	".$this->databasePrefix."members members
			ON		(recipients.id_member = members.id_member)
			ORDER BY	recipients.id_pm ASC, recipients.id_member ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$labels = array_map(function ($item) use ($row) {
				return $row['id_member'].'-'.$item;
			}, array_unique(ArrayUtil::toIntegerArray(explode(',', $row['labels']))));
			$labels = array_filter($labels, function ($item) {
				return $item != '-1';
			});
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.user')->import(0, array(
				'conversationID' => $row['id_pm_head'],
				'participantID' => $row['id_member'],
				'username' => $row['member_name'],
				'hideConversation' => $row['deleted'] ? 1 : 0,
				'isInvisible' => $row['bcc'] ? 1 : 0,
				'lastVisitTime' => $row['is_new'] ? 0 : 1
			), array('labelIDs' => $labels));
		}
	}
	
	/**
	 * Counts boards.
	 */
	public function countBoards() {
		$sql = "SELECT	(SELECT COUNT(*) FROM ".$this->databasePrefix."boards)
				+ (SELECT COUNT(*) FROM ".$this->databasePrefix."categories) AS count";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return ($row['count'] ? 1 : 0);
	}
	
	/**
	 * Exports boards.
	 */
	public function exportBoards($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."categories
			ORDER BY	id_cat ASC";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.board')->import('cat-'.$row['id_cat'], array(
				'parentID' => null,
				'position' => $row['cat_order'],
				'boardType' => Board::TYPE_CATEGORY,
				'title' => $row['name']
			));
		}
		
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."boards
			ORDER BY	id_board ASC";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$this->boardCache[$row['id_parent']][] = $row;
		}
		
		$this->exportBoardsRecursively();
	}

	/**
	 * Exports the boards recursively.
	 */
	protected function exportBoardsRecursively($parentID = 0) {
		if (!isset($this->boardCache[$parentID])) return;
		
		foreach ($this->boardCache[$parentID] as $board) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.board')->import($board['id_board'], array(
				'parentID' => ($board['id_parent'] ?: 'cat-'.$board['id_cat']),
				'position' => $board['board_order'],
				'boardType' => $board['redirect'] ? Board::TYPE_LINK : Board::TYPE_BOARD,
				'title' => $board['name'],
				'description' => $board['description'],
				'descriptionUseHtml' => 1,
				'externalURL' => $board['redirect'],
				'countUserPosts' => $board['count_posts'] ? 0 : 1, // this column name is SLIGHTLY misleading
				'clicks' => $board['num_posts'],
				'posts' => $board['num_posts'],
				'threads' => $board['num_topics']
			));
			
			$this->exportBoardsRecursively($board['id_board']);
		}
	}
	
	/**
	 * Counts threads.
	 */
	public function countThreads() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."topics";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports threads.
	 */
	public function exportThreads($offset, $limit) {
		// get threads
		$sql = "SELECT		topic.*, post.subject, post.poster_time AS time, post.poster_name AS username
			FROM		".$this->databasePrefix."topics topic
			LEFT JOIN	".$this->databasePrefix."messages post
			ON		(post.id_msg = topic.id_first_msg)
			ORDER BY	id_topic ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.thread')->import($row['id_topic'], array(
				'boardID' => $row['id_board'],
				'topic' => $row['subject'],
				'time' => $row['time'],
				'userID' => $row['id_member_started'],
				'username' => $row['username'],
				'views' => $row['num_views'],
				'isAnnouncement' => 0,
				'isSticky' => $row['is_sticky'] ? 1 : 0,
				'isDisabled' => $row['approved'] ? 0 : 1,
				'isClosed' => $row['locked'] ? 1 : 0,
				'movedThreadID' => null, // TODO: Maybe regex this out of the body?
				'movedTime' => 0
			));
		}
	}

	/**
	 * Counts posts.
	 */
	public function countPosts() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."messages";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports posts.
	 */
	public function exportPosts($offset, $limit) {
		$sql = "SELECT		message.*, member.id_member AS editorID
			FROM		".$this->databasePrefix."messages message
			LEFT JOIN	".$this->databasePrefix."members member
			ON		(message.modified_name = member.real_name)
			ORDER BY	id_msg";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.post')->import($row['id_msg'], array(
				'threadID' => $row['id_topic'],
				'userID' => $row['id_member'],
				'username' => $row['poster_name'],
				'subject' => $row['subject'],
				'message' => self::fixBBCodes($row['body']),
				'time' => $row['poster_time'],
				'isDisabled' => $row['approved'] ? 0 : 1,
				'editorID' => ($row['editorID'] ?: null),
				'editor' => $row['modified_name'],
				'lastEditTime' => $row['modified_time'],
				'editCount' => $row['modified_time'] ? 1 : 0,
				'editReason' => (!empty($row['editReason']) ? $row['editReason'] : ''),
				'enableSmilies' => $row['smileys_enabled'],
				'enableHtml' => 0,
				'enableBBCodes' => 1,
				'showSignature' => 1,
				'ipAddress' => UserUtil::convertIPv4To6($row['poster_ip'])
			));
		}
	}
	
	/**
	 * Counts post attachments.
	 */
	public function countPostAttachments() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."attachments
			WHERE		id_member = ?
				AND	id_msg <> ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(0, 0));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports post attachments.
	 */
	public function exportPostAttachments($offset, $limit) {
		$sql = "SELECT		attachment.*, message.id_member, message.poster_time
			FROM		".$this->databasePrefix."attachments attachment
			INNER JOIN	".$this->databasePrefix."messages message
			ON		(message.id_msg = attachment.id_msg)
			WHERE		attachment.id_member = ?
				AND	attachment.id_msg <> ?
			ORDER BY	attachment.id_attach ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(0, 0));
		while ($row = $statement->fetchArray()) {
			$fileLocation = $this->readOption('attachmentUploadDir').'/'.$row['id_attach'].'_'.$row['file_hash'];
			
			if ($imageSize = getimagesize($fileLocation)) {
				$row['isImage'] = 1;
				$row['width'] = $imageSize[0];
				$row['height'] = $imageSize[1];
			}
			else {
				$row['isImage'] = $row['width'] = $row['height'] = 0;
			}
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.attachment')->import($row['id_attach'], array(
				'objectID' => $row['id_msg'],
				'userID' => ($row['id_member'] ?: null),
				'filename' => $row['filename'],
				'filesize' => $row['size'],
				'fileType' => $row['mime_type'],
				'isImage' => $row['isImage'],
				'width' => $row['width'],
				'height' => $row['height'],
				'downloads' => $row['downloads'],
				'uploadTime' => $row['poster_time']
			), array('fileLocation' => $fileLocation));
		}
	}
	
	/**
	 * Counts watched threads.
	 */
	public function countWatchedThreads() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."log_notify
			WHERE		id_topic <> ?
				AND	id_board = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(0, 0));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports watched threads.
	 */
	public function exportWatchedThreads($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."log_notify
			WHERE			id_topic <> ?
					AND	id_board = ?
			ORDER BY	id_member ASC, id_topic ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(0, 0));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.watchedThread')->import(0, array(
				'objectID' => $row['id_topic'],
				'userID' => $row['id_member']
			));
		}
	}
	
	/**
	 * Counts polls.
	 */
	public function countPolls() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."polls";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports polls.
	 */
	public function exportPolls($offset, $limit) {
		$sql = "SELECT		poll.*, topic.id_first_msg,
					(SELECT COUNT(DISTINCT id_member) FROM ".$this->databasePrefix."log_polls vote WHERE poll.id_poll = vote.id_poll) AS votes
			FROM		".$this->databasePrefix."polls poll
			INNER JOIN	".$this->databasePrefix."topics topic
			ON		(topic.id_poll = poll.id_poll)
			ORDER BY	id_poll ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll')->import($row['id_poll'], array(
				'objectID' => $row['id_first_msg'],
				'question' => $row['question'],
				'endTime' => $row['expire_time'],
				'isChangeable' => $row['change_vote'] ? 1 : 0,
				'isPublic' => $row['hide_results'] ? 0 : 1,
				'maxVotes' => $row['max_votes'],
				'votes' => $row['votes']
			));
		}
	}
	
	/**
	 * Counts poll options.
	 */
	public function countPollOptions() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."poll_choices";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports poll options.
	 */
	public function exportPollOptions($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."poll_choices
			ORDER BY	id_poll ASC, id_choice ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll.option')->import($row['id_poll'].'-'.$row['id_choice'], array(
				'pollID' => $row['id_poll'],
				'optionValue' => $row['label'],
				'showOrder' => $row['id_choice'],
				'votes' => $row['votes']
			));
		}
	}
	
	/**
	 * Counts poll option votes.
	 */
	public function countPollOptionVotes() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."log_polls
			WHERE	id_member <> ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(0));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports poll option votes.
	 */
	public function exportPollOptionVotes($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."log_polls
			WHERE		id_member <> ?
			ORDER BY	id_poll ASC, id_member ASC, id_choice ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(0));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll.option.vote')->import(0, array(
				'pollID' => $row['id_poll'],
				'optionID' => $row['id_poll'].'-'.$row['id_choice'],
				'userID' => $row['id_member']
			));
		}
	}
	
	/**
	 * Counts ACLs.
	 */
	public function countACLs() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."forum_permissions";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports ACLs.
	 */
	public function exportACLs($offset, $limit) {
		static $permissionMap = array(
			'approve_posts' => array(
				'canEnableThread',
				'canEnablePost'
			),
			'delete_any' => array(
				'canDeletePost',
				'canReadDeletedPost',
				'canRestorePost',
				'canDeletePostCompletely'
			),
			'delete_own' => array('canDeleteOwnPost'),
			'lock_any' => array('canCloseThread', 'canClosePost'),
			'lock_own' => array(),
			'make_sticky' => array('canPinThread'),
			'mark_any_modify' => array(),
			'mark_modify' => array(),
			'merge_any' => array('canMergeThread'),
			'moderate_board' => array('canReplyClosedThread'),
			'modify_any' => array('canEditPost'),
			'modify_own' => array('canEditOwnPost'),
			'poll_add_any' => array(),
			'poll_add_own' => array(),
			'poll_edit_any' => array(),
			'poll_edit_own' => array(),
			'poll_lock_any' => array(),
			'poll_lock_own' => array(),
			'poll_post' => array('canStartPoll'),
			'poll_remove_any' => array(),
			'poll_view' => array(),
			'poll_vote' => array('canVotePoll'),
			'post_attachment' => array('canUploadAttachment'),
			'post_reply_any' => array('canReplyThread'),
			'post_reply_own' => array('canReplyOwnThread'),
			'post_unapproved_replies_any' => array('canReplyThreadWithoutModeration'),
			'post_unapproved_replies_own' => array(),
			'post_unapproved_topics' => array('canStartThreadWithoutModeration'),
			'remove_any' => array(
				'canDeleteThread',
				'canReadDeletedThread',
				'canRestoreThread',
				'canDeleteThreadCompletely'
			),
			'remove_own' => array(),
			'report_any' => array(),
			'send_topic' => array(),
			'split_any' => array(),
			'view_attachments' => array('canDownloadAttachment', 'canViewAttachmentPreview')
		);
	}
	
	/**
	 * Counts smilies.
	 */
	public function countSmilies() {
		$sql = "SELECT	COUNT(DISTINCT filename) AS count
			FROM	".$this->databasePrefix."smileys";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports smilies.
	 */
	public function exportSmilies($offset, $limit) {
		$sql = "SELECT		MIN(id_smiley) AS id_smiley,
					GROUP_CONCAT(code SEPARATOR '\n') AS aliases,
					filename,
					MIN(smiley_order) AS smiley_order,
					GROUP_CONCAT(description SEPARATOR '\n') AS description
			FROM		".$this->databasePrefix."smileys
			GROUP BY	filename
			ORDER BY	id_smiley ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array());
		while ($row = $statement->fetchArray()) {
			$fileLocation = $this->readOption('smiley_dir').'/'.$this->readOption('smiley_sets_default').'/'.$row['filename'];
			
			$aliases = explode("\n", $row['aliases']);
			$code = array_shift($aliases);
			$description = mb_substr($row['description'], 0, mb_strpos($row['description'], "\n") ?: mb_strlen($row['description'])); // we had to GROUP_CONCAT it because of SQL strict mode
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.smiley')->import($row['id_smiley'], array(
				'smileyTitle' => $description,
				'smileyCode' => $code,
				'showOrder' => $row['smiley_order'],
				'aliases' => implode("\n", $aliases)
			), array('fileLocation' => $fileLocation));
		}
	}
	
	private function readOption($optionName) {
		static $optionCache = array();
		
		if (!isset($optionCache[$optionName])) {
			$sql = "SELECT	value
				FROM	".$this->databasePrefix."settings
				WHERE	variable = ?";
			$statement = $this->database->prepareStatement($sql);
			$statement->execute(array($optionName));
			$row = $statement->fetchArray();
			
			$optionCache[$optionName] = $row['value'];
		}
		
		return $optionCache[$optionName];
	}
	
	private static function fixBBCodes($message) {
		$message = strtr($message, array(
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
			'[/php]' => '[/code]'
		));
		
		$message = Regex::compile('\[size=(8|10|12|14|18|24|34)pt\]')->replace($message, '[size=\\1]');
		
		$message = StringUtil::decodeHTML($message);
		
		return $message;
	}
}
