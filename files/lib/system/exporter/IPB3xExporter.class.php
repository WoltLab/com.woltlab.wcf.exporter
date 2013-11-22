<?php
namespace wcf\system\exporter;
use wbb\data\board\Board;
use wcf\data\like\Like;
use wcf\data\user\group\UserGroup;
use wcf\system\database\util\PreparedStatementConditionBuilder;
use wcf\system\importer\ImportHandler;
use wcf\system\WCF;
use wcf\util\StringUtil;
use wcf\util\UserUtil;

/**
 * Exporter for IP.Board 3.x
 * 
 * @author	Marcel Werk
 * @copyright	2001-2013 WoltLab GmbH
 * @license	WoltLab Burning Board License <http://www.woltlab.com/products/burning_board/license.php>
 * @package	com.woltlab.wcf.exporter
 * @subpackage	system.exporter
 * @category	Community Framework (commercial)
 */
class IPB3xExporter extends AbstractExporter {
	protected static $knownProfileFields = array('website', 'icq', 'gender', 'location', 'interests', 'skype');
	
	/**
	 * board cache
	 * @var	array
	 */
	protected $boardCache = array();
	
	/**
	 * @see	\wcf\system\exporter\AbstractExporter::$methods
	 */
	protected $methods = array(
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
		'com.woltlab.wbb.like' => 'Likes'
	);
	
	/**
	 * @see	\wcf\system\exporter\IExporter::getSupportedData()
	 */
	public function getSupportedData() {
		return array(
			'com.woltlab.wcf.user' => array(
				'com.woltlab.wcf.user.group',
				'com.woltlab.wcf.user.avatar',
				'com.woltlab.wcf.user.option',
				'com.woltlab.wcf.user.comment',
				'com.woltlab.wcf.user.follower'
			),
			'com.woltlab.wbb.board' => array(
				'com.woltlab.wbb.attachment',
				'com.woltlab.wbb.poll',
				'com.woltlab.wbb.watchedThread',
				'com.woltlab.wbb.like'
			),
			'com.woltlab.wcf.conversation' => array(
				'com.woltlab.wcf.conversation.attachment'
			)
		);
	}
	
	/**
	 * @see	\wcf\system\exporter\IExporter::validateDatabaseAccess()
	 */
	public function validateDatabaseAccess() {
		parent::validateDatabaseAccess();
	
		$sql = "SELECT COUNT(*) FROM ".$this->databasePrefix."core_like";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
	}
	
	/**
	 * @see	\wcf\system\exporter\IExporter::validateFileAccess()
	 */
	public function validateFileAccess() {
		if (in_array('com.woltlab.wcf.user.avatar', $this->selectedData) || in_array('com.woltlab.wbb.attachment', $this->selectedData) || in_array('com.woltlab.wcf.conversation.attachment', $this->selectedData)) {
			if (empty($this->fileSystemPath) || !@file_exists($this->fileSystemPath . 'conf_global.php')) return false;
		}
	
		return true;
	}
	
	/**
	 * @see	\wcf\system\exporter\IExporter::getQueue()
	 */
	public function getQueue() {
		$queue = array();
	
		// user
		if (in_array('com.woltlab.wcf.user', $this->selectedData)) {
			if (in_array('com.woltlab.wcf.user.group', $this->selectedData)) {
				$queue[] = 'com.woltlab.wcf.user.group';
			}
			if (in_array('com.woltlab.wcf.user.option', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.option';
			$queue[] = 'com.woltlab.wcf.user'; 
			if (in_array('com.woltlab.wcf.user.avatar', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.avatar';
				
			if (in_array('com.woltlab.wcf.user.comment', $this->selectedData)) {
				$queue[] = 'com.woltlab.wcf.user.comment';
				$queue[] = 'com.woltlab.wcf.user.comment.response';
			}
			
			if (in_array('com.woltlab.wcf.user.follower', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.follower';
			
			// conversation
			if (in_array('com.woltlab.wcf.conversation', $this->selectedData)) {
				$queue[] = 'com.woltlab.wcf.conversation';
				$queue[] = 'com.woltlab.wcf.conversation.message';
				$queue[] = 'com.woltlab.wcf.conversation.user';
					
				if (in_array('com.woltlab.wcf.conversation.attachment', $this->selectedData)) $queue[] = 'com.woltlab.wcf.conversation.attachment';
			}
		}
		
		// board
		if (in_array('com.woltlab.wbb.board', $this->selectedData)) {
			$queue[] = 'com.woltlab.wbb.board';
			
			
			$queue[] = 'com.woltlab.wbb.thread';
			$queue[] = 'com.woltlab.wbb.post';
			
			if (in_array('com.woltlab.wbb.attachment', $this->selectedData)) $queue[] = 'com.woltlab.wbb.attachment';
			if (in_array('com.woltlab.wbb.watchedThread', $this->selectedData)) $queue[] = 'com.woltlab.wbb.watchedThread';
			if (in_array('com.woltlab.wbb.poll', $this->selectedData)) {
				$queue[] = 'com.woltlab.wbb.poll';
				$queue[] = 'com.woltlab.wbb.poll.option.vote';
			}
			if (in_array('com.woltlab.wbb.like', $this->selectedData)) $queue[] = 'com.woltlab.wbb.like';
		}
	
		return $queue;
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
		// cache profile fields
		$profileFields = $knownProfileFields = array();
		$sql = "SELECT	*
			FROM	".$this->databasePrefix."pfields_data";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			if (in_array($row['pf_key'], self::$knownProfileFields)) {
				$knownProfileFields[$row['pf_key']] = $row;
			}
			else {
				$profileFields[] = $row;
			}
		}
	
		// prepare password update
		$sql = "UPDATE	wcf".WCF_N."_user
			SET	password = ?
			WHERE	userID = ?";
		$passwordUpdateStatement = WCF::getDB()->prepareStatement($sql);
	
		// get users
		$sql = "SELECT		pfields_content.*, members.*, profile_portal.*
			FROM		".$this->databasePrefix."members members
			LEFT JOIN	".$this->databasePrefix."profile_portal profile_portal
			ON		(profile_portal.pp_member_id = members.member_id)
			LEFT JOIN	".$this->databasePrefix."pfields_content pfields_content
			ON		(pfields_content.member_id = members.member_id)
			ORDER BY	members.member_id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$data = array(
				'username' => $row['name'],
				'password' => '',
				'email' => $row['email'],
				'registrationDate' => $row['joined'],
				'banned' => $row['member_banned'],
				'registrationIpAddress' => UserUtil::convertIPv4To6($row['ip_address']),
				'enableGravatar' => ((!empty($row['pp_gravatar']) && $row['pp_gravatar'] == $row['email']) ? 1 : 0),
				'signature' => self::fixMessage($row['signature']),
				'profileHits' => $row['members_profile_views'],
				'userTitle' => ($row['title'] ?: ''),
				'lastActivityTime' => $row['last_activity']
			);
			
			// get group ids
			$groupIDs = preg_split('/,/', $row['mgroup_others'], -1, PREG_SPLIT_NO_EMPTY);
			$groupIDs[] = $row['member_group_id'];
			
			// get user options
			$options = array(
				'timezone' => $row['time_offset'],
				'homepage' => (isset($knownProfileFields['website']) && !empty($row['field_'.$knownProfileFields['website']['pf_id']])) ? $row['field_'.$knownProfileFields['website']['pf_id']] : '',
				'icq' => (isset($knownProfileFields['icq']) && !empty($row['field_'.$knownProfileFields['icq']['pf_id']])) ? $row['field_'.$knownProfileFields['icq']['pf_id']] : '',
				'hobbies' => (isset($knownProfileFields['interests']) && !empty($row['field_'.$knownProfileFields['interests']['pf_id']])) ? $row['field_'.$knownProfileFields['interests']['pf_id']] : '',
				'skype' => (isset($knownProfileFields['skype']) && !empty($row['field_'.$knownProfileFields['skype']['pf_id']])) ? $row['field_'.$knownProfileFields['skype']['pf_id']] : '',
				'location' => (isset($knownProfileFields['location']) && !empty($row['field_'.$knownProfileFields['location']['pf_id']])) ? $row['field_'.$knownProfileFields['location']['pf_id']] : ''
			);
			
			// get birthday
			if ($row['bday_day'] && $row['bday_month'] && $row['bday_year']) {
				$options['birthday'] = $row['bday_year'].'-'.($row['bday_month'] < 10 ? '0' : '').$row['bday_month'].'-'.($row['bday_day'] < 10 ? '0' : '').$row['bday_day'];
			}
			
			// get gender
			if (isset($knownProfileFields['gender']) && !empty($row['field_'.$knownProfileFields['gender']['pf_id']])) {
				$gender = $row['field_'.$knownProfileFields['gender']['pf_id']];
				if ($gender == 'm') $options['gender'] = 1;
				if ($gender == 'f') $options['gender'] = 2;
			}
			
			$additionalData = array(
				'groupIDs' => $groupIDs,
				'options' => $options
			);
				
			// handle user options
			foreach ($profileFields as $profileField) {
				if (!empty($row['field_'.$profileField['pf_id']])) {
					$additionalData['options'][$profileField['pf_id']] = $row['field_'.$profileField['pf_id']];
				}
			}
				
			// import user
			$newUserID = ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user')->import($row['member_id'], $data, $additionalData);
				
			// update password hash
			if ($newUserID) {
				$passwordUpdateStatement->execute(array('ipb3:'.$row['members_pass_hash'].':'.$row['members_pass_salt'], $newUserID));
			}
		}
	}
	
	/**
	 * Counts user options.
	 */
	public function countUserOptions() {
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('pf_key NOT IN (?)', array(self::$knownProfileFields));
		
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."pfields_data
			".$conditionBuilder;
		$statement = $this->database->prepareStatement($sql);
		$statement->execute($conditionBuilder->getParameters());
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports user options.
	 */
	public function exportUserOptions($offset, $limit) {
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('pf_key NOT IN (?)', array(self::$knownProfileFields));
		
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."pfields_data
			".$conditionBuilder."
			ORDER BY	pf_id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute($conditionBuilder->getParameters());
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.option')->import($row['pf_id'], array(
				'categoryName' => 'profile.personal',
				'optionType' => 'textarea',
				'askDuringRegistration' => $row['pf_show_on_reg'],
			), array('name' => $row['pf_title']));
		}
	}
	
	/**
	 * Counts user groups.
	 */
	public function countUserGroups() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."groups";
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
			FROM		".$this->databasePrefix."groups
			ORDER BY	g_id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
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
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.group')->import($row['g_id'], array(
				'groupName' => $row['g_title'],
				'groupType' => $groupType,
				'userOnlineMarking' => (!empty($row['prefix']) ? ($row['prefix'].'%s'.$row['suffix']) : '')
			));
		}
	}
	
	/**
	 * Counts user avatars.
	 */
	public function countUserAvatars() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."profile_portal
			WHERE	avatar_location <> ''
				OR pp_main_photo <> ''";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports user avatars.
	 */
	public function exportUserAvatars($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."profile_portal
			WHERE		avatar_location <> ''
					OR pp_main_photo <> ''
			ORDER BY	pp_member_id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			if ($row['pp_main_photo']) {
				$avatarName = basename($row['pp_main_photo']);
				
				$source = $this->fileSystemPath.'uploads/'.$row['pp_main_photo'];
			}
			else {
				$avatarName = basename($row['avatar_location']);
				
				$source = '';
				if ($row['avatar_type'] != 'url') {
					$source = $this->fileSystemPath;
					if ($row['avatar_type'] == 'upload') $source .= 'uploads/';
					else $source .= 'style_avatars/';
				}
				$source .= $row['avatar_location'];
			}
			
			$avatarExtension = pathinfo($avatarName, PATHINFO_EXTENSION);
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.avatar')->import($row['pp_member_id'], array(
				'avatarName' => $avatarName,
					'avatarExtension' => $avatarExtension,
				'userID' => $row['pp_member_id']
			), array('fileLocation' => $source));
		}
	}
	
	/**
	 * Counts status updates.
	 */
	public function countStatusUpdates() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."member_status_updates";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports status updates.
	 */
	public function exportStatusUpdates($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."member_status_updates
			ORDER BY	status_id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.comment')->import($row['status_id'], array(
				'objectID' => $row['status_member_id'],
				'userID' => $row['status_author_id'],
				'username' => $row['status_creator'],
				'message' => $row['status_content'],
				'time' => $row['status_date']
			));
		}
	}
	
	/**
	 * Counts status replies.
	 */
	public function countStatusReplies() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."member_status_replies";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports status replies.
	 */
	public function exportStatusReplies($offset, $limit) {
		$sql = "SELECT		member_status_replies.*, members.name
			FROM		".$this->databasePrefix."member_status_replies member_status_replies
			LEFT JOIN	".$this->databasePrefix."members members
			ON		(members.member_id = member_status_replies.reply_member_id)
			ORDER BY	member_status_replies.reply_id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.comment.response')->import($row['reply_id'], array(
				'commentID' => $row['reply_status_id'],
				'time' => $row['reply_date'],
				'userID' => $row['reply_member_id'],
				'username' => $row['name'],
				'message' => $row['reply_content'],
			));
		}
	}
	
	/**
	 * Counts followers.
	 */
	public function countFollowers() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."profile_friends";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports followers.
	 */
	public function exportFollowers($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."profile_friends
			ORDER BY	friends_id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.follower')->import(0, array(
				'userID' => $row['friends_member_id'],
				'followUserID' => $row['friends_friend_id'],
				'time' => $row['friends_added']
			));
		}
	}
	
	/**
	 * Counts conversations.
	 */
	public function countConversations() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."message_topics";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports conversations.
	 */
	public function exportConversations($offset, $limit) {
		$sql = "SELECT		message_topics.*, members.name
			FROM		".$this->databasePrefix."message_topics message_topics
			LEFT JOIN	".$this->databasePrefix."members members
			ON		(members.member_id = message_topics.mt_starter_id)
			ORDER BY	message_topics.mt_id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation')->import($row['mt_id'], array(
				'subject' => $row['mt_title'],
				'time' => $row['mt_date'],
				'userID' => ($row['mt_starter_id'] ?: null),
				'username' => ($row['mt_is_system'] ? 'System' : ($row['name'] ?: '')),
				'isDraft' => $row['mt_is_draft']
			));
		}
	}
	
	/**
	 * Counts conversation messages.
	 */
	public function countConversationMessages() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."message_posts";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports conversation messages.
	 */
	public function exportConversationMessages($offset, $limit) {
		$sql = "SELECT		message_posts.*, members.name
			FROM		".$this->databasePrefix."message_posts message_posts
			LEFT JOIN	".$this->databasePrefix."members members
			ON		(members.member_id = message_posts.msg_author_id)
			ORDER BY	message_posts.msg_id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.message')->import($row['msg_id'], array(
				'conversationID' => $row['msg_topic_id'],
				'userID' => ($row['msg_author_id'] ?: null),
				'username' => ($row['name'] ?: ''),
				'message' => self::fixMessage($row['msg_post']),
				'time' => $row['msg_date']
			));
		}
	}
	
	/**
	 * Counts conversation recipients.
	 */
	public function countConversationUsers() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."message_topic_user_map";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports conversation recipients.
	 */
	public function exportConversationUsers($offset, $limit) {
		$sql = "SELECT		message_topic_user_map.*, members.name
			FROM		".$this->databasePrefix."message_topic_user_map message_topic_user_map
			LEFT JOIN	".$this->databasePrefix."members members
			ON		(members.member_id = message_topic_user_map.map_user_id)
			ORDER BY	message_topic_user_map.map_id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.user')->import(0, array(
				'conversationID' => $row['map_topic_id'],
				'participantID' => $row['map_user_id'],
				'username' => $row['name'],
				'hideConversation' => ($row['map_left_time'] ? 1 : 0),
				'isInvisible' => 0,
				'lastVisitTime' => $row['map_read_time']
			));
		}
	}
	
	/**
	 * Counts conversation attachments.
	 */
	public function countConversationAttachments() {
		return $this->countAttachments('msg');
	}
	
	/**
	 * Exports conversation attachments.
	 */
	public function exportConversationAttachments($offset, $limit) {
		$this->exportAttachments('msg', 'com.woltlab.wcf.conversation.attachment', $offset, $limit);
	}
	
	/**
	 * Counts boards.
	 */
	public function countBoards() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."forums";
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
			FROM		".$this->databasePrefix."forums
			ORDER BY	parent_id, id";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$this->boardCache[$row['parent_id']][] = $row;
		}
	
		$this->exportBoardsRecursively();
	}
	
	/**
	 * Exports the boards recursively.
	 */
	protected function exportBoardsRecursively($parentID = -1) {
		if (!isset($this->boardCache[$parentID])) return;
	
		foreach ($this->boardCache[$parentID] as $board) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.board')->import($board['id'], array(
				'parentID' => ($board['parent_id'] != -1 ? $board['parent_id'] : null),
				'position' => $board['position'],
				'boardType' => ($board['redirect_on'] ? Board::TYPE_LINK : ($board['sub_can_post'] ? Board::TYPE_BOARD : Board::TYPE_CATEGORY)),
				'title' => $board['name'],
				'description' => $board['description'],
				'externalURL' => $board['redirect_url'],
				'countUserPosts' => $board['inc_postcount'],
				'clicks' => $board['redirect_hits'],
				'posts' => $board['posts'],
				'threads' => $board['topics']
			));
				
			$this->exportBoardsRecursively($board['id']);
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
		// get thread ids
		$threadIDs = array();
		$sql = "SELECT		tid
			FROM		".$this->databasePrefix."topics
			ORDER BY	tid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$threadIDs[] = $row['tid'];
		}
	
		// get tags
		$tags = $this->getTags('forums', 'topics', $threadIDs);
	
		// get threads
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('topics.tid IN (?)', array($threadIDs));
	
		$sql = "SELECT		topics.*
			FROM		".$this->databasePrefix."topics topics
			".$conditionBuilder;
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute($conditionBuilder->getParameters());
		while ($row = $statement->fetchArray()) {
			$data = array(
				'boardID' => $row['forum_id'],
				'topic' => $row['title'],
				'time' => $row['start_date'],
				'userID' => $row['starter_id'],
				'username' => $row['starter_name'],
				'views' => $row['views'],
				'isSticky' => $row['pinned'],
				'isDisabled' => ($row['approved'] == 0 ? 1 : 0),
				'isClosed' => ($row['state'] == 'close' ? 1 : 0),
				'isDeleted' => ($row['tdelete_time'] ? 1 : 0),
				'movedThreadID' => ($row['moved_to'] ? intval($row['moved_to']) : null),
				'movedTime' => $row['moved_on'],
				'deleteTime' => $row['tdelete_time'],
				'lastPostTime' => $row['last_post']
			);
			$additionalData = array();
			if (isset($tags[$row['tid']])) $additionalData['tags'] = $tags[$row['tid']];
				
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.thread')->import($row['tid'], $data, $additionalData);
		}
	}
	
	/**
	 * Counts posts.
	 */
	public function countPosts() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."posts";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports posts.
	 */
	public function exportPosts($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."posts
			ORDER BY	pid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.post')->import($row['pid'], array(
				'threadID' => $row['topic_id'],
				'userID' => $row['author_id'],
				'username' => $row['author_name'],
				'message' => self::fixMessage($row['post']),
				'time' => $row['post_date'],
				'isDeleted' => ($row['queued'] == 3 ? 1 : 0),
				'isDisabled' => ($row['queued'] == 2 ? 1 : 0),
				'lastEditTime' => ($row['edit_time'] ?: 0),
				'editorID' => null,
				'editReason' => $row['post_edit_reason'],
				'ipAddress' => UserUtil::convertIPv4To6($row['ip_address']),
				'deleteTime' => $row['pdelete_time']
			));
		}
	}
	
	/**
	 * Counts watched threads.
	 */
	public function countWatchedThreads() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."core_like
			WHERE	like_app = ?
				AND like_area = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array('forums', 'topics'));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports watched threads.
	 */
	public function exportWatchedThreads($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."core_like
			WHERE		like_app = ?
					AND like_area = ?
			ORDER BY	like_id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array('forums', 'topics'));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.watchedThread')->import(0, array(
				'objectID' => $row['like_rel_id'],
				'userID' => $row['like_member_id']
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
		$sql = "SELECT		polls.*, topics.topic_firstpost
			FROM		".$this->databasePrefix."polls polls
			LEFT JOIN	".$this->databasePrefix."topics topics
			ON		(topics.tid = polls.tid)		
			ORDER BY	pid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$data = @unserialize($row['choices']);
			if (!$data || !isset($data[1])) continue; 

			// import poll
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll')->import($row['pid'], array(
				'objectID' => $row['topic_firstpost'],
				'question' => $data[1]['question'],
				'time' => $row['start_date'],
				'isPublic' => $row['poll_view_voters'],
				'maxVotes' => ($data[1]['multi'] ? count($data[1]['choice']) : 1),
				'votes' => $row['votes']
			));
			
			// import poll options
			foreach ($data[1]['choice'] as $key => $choice) {
				ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll.option')->import($row['pid'].'-'.$key, array(
					'pollID' => $row['pid'],
					'optionValue' => $choice,
					'showOrder' => $key,
					'votes' => $data[1]['votes'][$key]
				));
			}
		}
	}
	
	/**
	 * Counts poll option votes.
	 */
	public function countPollOptionVotes() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."voters";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports poll option votes.
	 */
	public function exportPollOptionVotes($offset, $limit) {
		$sql = "SELECT		polls.*, voters.*
			FROM		".$this->databasePrefix."voters voters
			LEFT JOIN	".$this->databasePrefix."polls polls
			ON		(polls.tid = voters.tid)
			ORDER BY	voters.vid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$data = @unserialize($row['member_choices']);
			if (!$data || !isset($data[1])) continue;
			
			foreach ($data[1] as $pollOptionKey) {
				ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll.option.vote')->import(0, array(
					'pollID' => $row['pid'],
					'optionID' => $row['pid'].'-'.$pollOptionKey,
					'userID' => $row['member_id']
				));
			}
		}
	}
	
	/**
	 * Counts likes.
	 */
	public function countLikes() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."core_like
			WHERE	like_app = ?
				AND like_area = ?
				AND like_visible = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array('forums', 'topics', 1));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports likes.
	 */
	public function exportLikes($offset, $limit) {
		$sql = "SELECT		core_like.*, topics.topic_firstpost, topics.starter_id
			FROM		".$this->databasePrefix."core_like core_like
			LEFT JOIN	".$this->databasePrefix."topics topics
			ON		(topics.tid = core_like.like_rel_id)
			WHERE		core_like.like_app = ?
					AND core_like.like_area = ?
					AND core_like.like_visible = ?
			ORDER BY	core_like.like_id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array('forums', 'topics', 1));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.like')->import(0, array(
				'objectID' => $row['topic_firstpost'],
				'objectUserID' => ($row['starter_id'] ?: null),
				'userID' => $row['like_member_id'],
				'likeValue' => Like::LIKE
			));
		}
	}
	
	/**
	 * Counts post attachments.
	 */
	public function countPostAttachments() {
		return $this->countAttachments('post');
	}
	
	/**
	 * Exports post attachments.
	 */
	public function exportPostAttachments($offset, $limit) {
		$this->exportAttachments('post', 'com.woltlab.wbb.attachment', $offset, $limit);
	}
	
	private function countAttachments($type) {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."attachments
			WHERE	attach_rel_module = ?
				AND attach_rel_id > ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array($type, 0));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	private function exportAttachments($type, $objectType, $offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."attachments
			WHERE		attach_rel_module = ?
					AND attach_rel_id > ?
			ORDER BY	attach_id DESC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array($type, 0));
		while ($row = $statement->fetchArray()) {
			$fileLocation = $this->fileSystemPath.'uploads/'.$row['attach_location'];

			ImportHandler::getInstance()->getImporter($objectType)->import($row['attach_id'], array(
				'objectID' => $row['attach_rel_id'],
				'userID' => ($row['attach_member_id'] ?: null),
				'filename' => $row['attach_file'],
				'filesize' => $row['attach_filesize'],
				'isImage' => $row['attach_is_image'],
				'downloads' => $row['attach_hits'],
				'uploadTime' => $row['attach_date'],
			), array('fileLocation' => $fileLocation));
		}
	}
	
	private function getTags($app, $area, array $objectIDs) {
		$tags = array();
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('tag_meta_app = ?', array($app));
		$conditionBuilder->add('tag_meta_area = ?', array($area));
		$conditionBuilder->add('tag_meta_id IN (?)', array($objectIDs));
	
		// get taggable id
		$sql = "SELECT		tag_meta_id, tag_text
			FROM		".$this->databasePrefix."core_tags
			".$conditionBuilder;
		$statement = $this->database->prepareStatement($sql);
		$statement->execute($conditionBuilder->getParameters());
		while ($row = $statement->fetchArray()) {
			if (!isset($tags[$row['tag_meta_id']])) $tags[$row['tag_meta_id']] = array();
			$tags[$row['tag_meta_id']][] = $row['tag_text'];
		}
		
		return $tags;
	}
	
	private static function fixMessage($string) {
		// <br /> to newline
		$string = str_ireplace('<br />', "\n", $string);
		$string = str_ireplace('<br>', "\n", $string);
		
		// decode html entities
		$string = StringUtil::decodeHTML($string);
		
		// bold
		$string = str_ireplace('<strong>', '[b]', $string);
		$string = str_ireplace('</strong>', '[/b]', $string);
		
		// italic
		$string = str_ireplace('<em>', '[i]', $string);
		$string = str_ireplace('</em>', '[/i]', $string);
		
		// underline
		$string = str_ireplace('<u>', '[u]', $string);
		$string = str_ireplace('</u>', '[/u]', $string);
		
		// strike
		$string = str_ireplace('<strike>', '[s]', $string);
		$string = str_ireplace('</strike>', '[/s]', $string);
		
		// font face
		$string = preg_replace_callback('~<span style="font-family:(.*?)">(.*?)</span>~i', function ($matches) {
			return "[font='".str_replace(";", '', str_replace("'", '', $matches[1]))."']".$matches[2]."[/font]";
		}, $string);
		
		// font size
		$string = preg_replace('~<span style="font-size:(\d+)px;">(.*?)</span>~i', '[size=\\1]\\2[/size]', $string);
		
		// font color
		$string = preg_replace('~<span style="color:(.*?);?">(.*?)</span>~i', '[color=\\1]\\2[/color]', $string);
		
		// align
		$string = preg_replace('~<p style="text-align:(left|center|right);">(.*?)</p>~i', '[align=\\1]\\2[/align]', $string);
		
		// list
		$string = str_ireplace('</ol>', '[/list]', $string);
		$string = str_ireplace('</ul>', '[/list]', $string);
		$string = str_ireplace('<ul>', '[list]', $string);
		$string = str_ireplace("<ol type='1'>", '[list=1]', $string);
		$string = str_ireplace("<ol>", '[list=1]', $string);
		$string = str_ireplace('<li>', '[*]', $string);
		$string = str_ireplace('</li>', '', $string);
		
		// mails
		$string = preg_replace('~<a.*?href=(?:"|\')mailto:([^"]*)(?:"|\')>(.*?)</a>~is', '[email=\'\\1\']\\2[/email]', $string);
		
		// urls
		$string = preg_replace('~<a.*?href=(?:"|\')([^"]*)(?:"|\')>(.*?)</a>~is', '[url=\'\\1\']\\2[/url]', $string);
		
		// images
		$string = preg_replace('~<img[^>]+src="([^"]+)"[^>]+/?>~is', '[img]\\1[/img]', $string);
		
		// quotes
		$string = preg_replace('~<blockquote[^>]*>(.*?)</blockquote>~is', '[quote]\\1[/quote]', $string);
		
		// code
		$string = preg_replace('~<pre[^>]*>(.*?)</pre>~is', '[code]\\1[/code]', $string);
		
		// embedded attachments
		$string = preg_replace('~\[attachment=(\d+):[^\]]*\]~i', '[attach]\\1[/attach]', $string);
		
		// remove obsolete code
		$string = str_ireplace('<p>&nbsp;</p>', '', $string);
		$string = str_ireplace('<p>', '', $string);
		$string = str_ireplace('</p>', '', $string);

		return $string;
	}
}