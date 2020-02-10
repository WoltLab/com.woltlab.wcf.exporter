<?php
namespace wcf\system\exporter;
use wbb\data\board\Board;
use wcf\data\conversation\Conversation;
use wcf\data\like\Like;
use wcf\data\object\type\ObjectTypeCache;
use wcf\data\user\group\UserGroup;
use wcf\data\user\option\UserOption;
use wcf\system\database\util\PreparedStatementConditionBuilder;
use wcf\system\exception\SystemException;
use wcf\system\importer\ImportHandler;
use wcf\system\request\LinkHandler;
use wcf\system\Regex;
use wcf\system\WCF;
use wcf\util\FileUtil;
use wcf\util\MessageUtil;
use wcf\util\PasswordUtil;
use wcf\util\UserUtil;

/**
 * Exporter for XenForo 1.2.x - 1.4.x
 * 
 * @author	Tim Duesterhus
 * @copyright	2001-2019 WoltLab GmbH
 * @license	GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package	com.woltlab.wcf.exporter
 * @subpackage	system.exporter
 * @category	Community Framework
 */
class XF12xExporter extends AbstractExporter {
	protected static $knownProfileFields = ['facebook', 'icq', 'twitter', 'skype'];
	
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
		'com.woltlab.wcf.user.comment' => 'WallEntries',
		'com.woltlab.wcf.user.comment.response' => 'WallResponses',
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
		'com.woltlab.wcf.smiley' => 'Smilies',
		
		'com.woltlab.blog.category' => 'BlogCategories',
		'com.woltlab.blog.entry' => 'BlogEntries',
		'com.woltlab.blog.entry.attachment' => 'BlogAttachments',
		'com.woltlab.blog.entry.comment' => 'BlogComments',
		'com.woltlab.blog.entry.like' => 'BlogEntryLikes'
	];
	
	/**
	 * @inheritDoc
	 */
	protected $limits = [
		'com.woltlab.wcf.user' => 200,
		'com.woltlab.wcf.user.avatar' => 100,
		'com.woltlab.wcf.user.follower' => 100
	];
	
	/**
	 * @inheritDoc
	 */
	public function getSupportedData() {
		return [
			'com.woltlab.wcf.user' => [
				'com.woltlab.wcf.user.group',
				'com.woltlab.wcf.user.avatar',
				'com.woltlab.wcf.user.option',
				'com.woltlab.wcf.user.comment',
				'com.woltlab.wcf.user.follower',
				'com.woltlab.wcf.user.rank'
			],
			'com.woltlab.wbb.board' => [
				'com.woltlab.wbb.acl',
				'com.woltlab.wbb.attachment',
				'com.woltlab.wbb.poll',
				'com.woltlab.wbb.watchedThread',
				'com.woltlab.wcf.label'
			],
			'com.woltlab.wcf.conversation' => [
				'com.woltlab.wcf.conversation.label'
			],
			'com.woltlab.blog.entry' => [
				'com.woltlab.blog.category',
				'com.woltlab.blog.entry.attachment',
				'com.woltlab.blog.entry.comment',
				'com.woltlab.blog.entry.like'
			]
		];
	}
	
	/**
	 * @inheritDoc
	 */
	public function validateDatabaseAccess() {
		parent::validateDatabaseAccess();
		
		$sql = "SELECT COUNT(*) FROM xf_template_compiled";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
	}
	
	/**
	 * @inheritDoc
	 */
	public function validateFileAccess() {
		if (in_array('com.woltlab.wcf.user.avatar', $this->selectedData) || in_array('com.woltlab.wbb.attachment', $this->selectedData) || in_array('com.woltlab.blog.entry.attachment', $this->selectedData) || in_array('com.woltlab.wcf.smiley', $this->selectedData)) {
			if (empty($this->fileSystemPath) || !@file_exists($this->fileSystemPath . 'library/XenForo/Application.php')) return false;
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
			}
			
			if (in_array('com.woltlab.wcf.user.rank', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.rank';
			
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
				if (in_array('com.woltlab.wcf.conversation.label', $this->selectedData)) $queue[] = 'com.woltlab.wcf.conversation.label';
				
				$queue[] = 'com.woltlab.wcf.conversation';
				$queue[] = 'com.woltlab.wcf.conversation.message';
				$queue[] = 'com.woltlab.wcf.conversation.user';
			}
		}
		
		// board
		if (in_array('com.woltlab.wbb.board', $this->selectedData)) {
			$queue[] = 'com.woltlab.wbb.board';
			if (in_array('com.woltlab.wcf.label', $this->selectedData)) $queue[] = 'com.woltlab.wcf.label';
			$queue[] = 'com.woltlab.wbb.thread';
			$queue[] = 'com.woltlab.wbb.post';
			
			if (in_array('com.woltlab.wbb.acl', $this->selectedData)) $queue[] = 'com.woltlab.wbb.acl';
			if (in_array('com.woltlab.wbb.attachment', $this->selectedData)) $queue[] = 'com.woltlab.wbb.attachment';
			if (in_array('com.woltlab.wbb.watchedThread', $this->selectedData)) $queue[] = 'com.woltlab.wbb.watchedThread';
			if (in_array('com.woltlab.wbb.poll', $this->selectedData)) {
				$queue[] = 'com.woltlab.wbb.poll';
				$queue[] = 'com.woltlab.wbb.poll.option';
				$queue[] = 'com.woltlab.wbb.poll.option.vote';
			}
		}
		
		if (in_array('com.woltlab.blog.entry', $this->selectedData)) {
			if (in_array('com.woltlab.blog.category', $this->selectedData)) $queue[] = 'com.woltlab.blog.category';
			$queue[] = 'com.woltlab.blog.entry';
			if (in_array('com.woltlab.blog.entry.attachment', $this->selectedData)) $queue[] = 'com.woltlab.blog.entry.attachment';
			if (in_array('com.woltlab.blog.entry.comment', $this->selectedData)) $queue[] = 'com.woltlab.blog.entry.comment';
			if (in_array('com.woltlab.blog.entry.like', $this->selectedData)) $queue[] = 'com.woltlab.blog.entry.like';
		}
		
		return $queue;
	}
	
	/**
	 * @inheritDoc
	 */
	public function getDefaultDatabasePrefix() {
		return 'xf_';
	}
	
	/**
	 * Counts user groups.
	 */
	public function countUserGroups() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	xf_user_group";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports user groups.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportUserGroups($offset, $limit) {
		$sql = "SELECT		*
			FROM		xf_user_group
			ORDER BY	user_group_id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.group')->import($row['user_group_id'], [
				'groupName' => $row['title'],
				'groupType' => UserGroup::OTHER,
				'userOnlineMarking' => $row['username_css'] ? '<span style="'.str_replace(["\n", "\r"], '', $row['username_css']).'">%s</span>' : '%s',
				'priority' => $row['display_style_priority']
			]);
		}
	}
	
	/**
	 * Counts users.
	 */
	public function countUsers() {
		return $this->__getMaxID("xf_user", 'user_id');
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
		$sql = "SELECT		user_table.*, user_profile_table.*, INET_NTOA(ip_table.ip) AS ip,
					authenticate_table.scheme_class, authenticate_table.data AS passwordData,
					language_table.language_code
			FROM		xf_user user_table
			LEFT JOIN	xf_user_profile user_profile_table
			ON		user_table.user_id = user_profile_table.user_id
			LEFT JOIN	xf_user_authenticate authenticate_table
			ON		user_table.user_id = authenticate_table.user_id
			LEFT JOIN	xf_language language_table
			ON		user_table.language_id = language_table.language_id
			LEFT JOIN	xf_ip ip_table
			ON		user_table.user_id = ip_table.user_id
					AND content_type = ?
					AND action = ?
			WHERE		user_table.user_id BETWEEN ? AND ?
			ORDER BY	user_table.user_id";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(['user', 'register', $offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			$data = [
				'username' => $row['username'],
				'password' => null,
				'email' => $row['email'],
				'registrationDate' => $row['register_date'],
				'banned' => $row['is_banned'] ? 1 : 0,
				'banReason' => '',
				'registrationIpAddress' => $row['ip'] ? UserUtil::convertIPv4To6($row['ip']) : '',
				'signature' => self::fixBBCodes($row['signature']),
				'lastActivityTime' => $row['last_activity']
			];
			$options = [
				'location' => $row['location'],
				'occupation' => $row['occupation'],
				'homepage' => $row['homepage'],
				'aboutMe' => self::fixBBCodes($row['about']),
				'birthday' => $row['dob_year'].'-'.$row['dob_month'].'-'.$row['dob_day']
			];
			
			$customFields = unserialize($row['custom_fields']);
			
			if ($customFields) {
				foreach ($customFields as $key => $value) {
					if (is_array($value)) continue;
					
					if (in_array($key, self::$knownProfileFields)) {
						$options[$key] = $value;
						continue;
					}
					
					$options[hexdec(substr(sha1($key), 0, 7))] = $value;
				}
			}
			
			$languageCode = '';
			if ($row['language_code']) list($languageCode, ) = explode('-', $row['language_code'], 2);
			
			$additionalData = [
				'groupIDs' => explode(',', $row['secondary_group_ids'].','.$row['user_group_id']),
				'languages' => [$languageCode],
				'options' => $options
			];
			
			// import user
			$newUserID = ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user')->import($row['user_id'], $data, $additionalData);
			
			// update password hash
			if ($newUserID) {
				$passwordData = unserialize($row['passwordData']);
				switch ($row['scheme_class']) {
					case 'XenForo_Authentication_Core12':
						$password = PasswordUtil::getSaltedHash($passwordData['hash'], $passwordData['hash']);
					break;
					
					case 'XenForo_Authentication_Core':
						$password = 'xf1:'.$passwordData['hash'].':'.$passwordData['salt'];
					break;
					
					case 'XenForo_Authentication_MyBb':
						$password = 'mybb:'.$passwordData['hash'].':'.$passwordData['salt'];
					break;
					
					case 'XenForo_Authentication_IPBoard':
						$password = 'ipb3:'.$passwordData['hash'].':'.$passwordData['salt'];
					break;
					
					case 'XenForo_Authentication_vBulletin':
						$password = 'vb3:'.$passwordData['hash'].':'.$passwordData['salt'];
					break;
					
					case 'XenForo_Authentication_PhpBb3':
						$password = 'phpbb3:'.$passwordData['hash'].':';
					break;
					
					case 'XenForo_Authentication_NoPassword':
						$password = 'invalid:-:-';
					break;
				}
				/** @noinspection PhpUndefinedVariableInspection */
				$passwordUpdateStatement->execute([$password, $newUserID]);
			}
		}
	}
	
	/**
	 * Counts user options.
	 */
	public function countUserOptions() {
		$condition = new PreparedStatementConditionBuilder();
		$condition->add('field_id NOT IN (?)', [self::$knownProfileFields]);
		
		$sql = "SELECT	COUNT(*) AS count
			FROM	xf_user_field
			".$condition;
		$statement = $this->database->prepareStatement($sql);
		$statement->execute($condition->getParameters());
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports user options.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportUserOptions($offset, $limit) {
		$condition = new PreparedStatementConditionBuilder();
		$condition->add('field_id NOT IN (?)', [self::$knownProfileFields]);
		
		$sql = "SELECT	*
			FROM	xf_user_field
			".$condition."
			ORDER BY	field_id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute($condition->getParameters());
		while ($row = $statement->fetchArray()) {
			switch ($row['field_type']) {
				case 'textarea':
				case 'select':
					// fine
					break;
				case 'textbox':
					$row['field_type'] = 'text';
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
				
			$selectOptions = [];
			if ($row['field_choices']) {
				$field_choices = @unserialize($row['field_choices']);
				if (!$field_choices) continue;
				foreach ($field_choices as $key => $value) {
					$selectOptions[] = $key.':'.$value;
				}
			}
			
			// the ID is transformed into an integer, because the importer cannot handle strings as IDs
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.option')->import(hexdec(substr(sha1($row['field_id']), 0, 7)), [
				'categoryName' => 'profile.personal',
				'optionType' => $row['field_type'],
				'editable' => $row['user_editable'] == 'yes' ? UserOption::EDITABILITY_ALL : UserOption::EDITABILITY_ADMINISTRATOR,
				'required' => $row['required'] ? 1 : 0,
				'askDuringRegistration' => $row['show_registration'] ? 1 : 0,
				'selectOptions' => implode("\n", $selectOptions),
				'visible' => UserOption::VISIBILITY_ALL,
				'outputClass' => $row['field_type'] == 'select' ? 'wcf\system\option\user\SelectOptionsUserOptionOutput' : '',
			], ['name' => $row['field_id']]);
		}
	}
	
	/**
	 * Counts user ranks.
	 */
	public function countUserRanks() {
		try {
			$sql = "SELECT	COUNT(*) AS count
				FROM	xf_user_title_ladder";
			$statement = $this->database->prepareStatement($sql);
		}
		catch (SystemException $e) {
			$sql = "SELECT	COUNT(*) AS count
				FROM	xf_trophy_user_title";
			$statement = $this->database->prepareStatement($sql);
		}
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports user ranks.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportUserRanks($offset, $limit) {
		try {
			$sql = "SELECT		*
				FROM		xf_user_title_ladder
				ORDER BY	minimum_level";
			$statement = $this->database->prepareStatement($sql, $limit, $offset);
		}
		catch (SystemException $e) {
			$sql = "SELECT		minimum_points AS minimum_level, title
				FROM		xf_trophy_user_title
				ORDER BY	minimum_points";
			$statement = $this->database->prepareStatement($sql, $limit, $offset);
		}
		
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.rank')->import($row['minimum_level'], [
				'groupID' => 2, // 2 = registered users
				'requiredPoints' => $row['minimum_level'],
				'rankTitle' => $row['title'],
				'rankImage' => '',
				'repeatImage' => 0,
				'requiredGender' => 0 // neutral
			]);
		}
	}
	
	/**
	 * Counts followers.
	 */
	public function countFollowers() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	xf_user_follow";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports followers.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportFollowers($offset, $limit) {
		$sql = "SELECT		*
			FROM		xf_user_follow
			ORDER BY	user_id, follow_user_id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.follower')->import(0, [
				'userID' => $row['user_id'],
				'followUserID' => $row['follow_user_id'],
				'time' => $row['follow_date']
			]);
		}
	}
	
	/**
	 * Counts wall entries.
	 */
	public function countWallEntries() {
		return $this->__getMaxID("xf_profile_post", 'profile_post_id');
	}
	
	/**
	 * Exports wall entries.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportWallEntries($offset, $limit) {
		$sql = "SELECT		*
			FROM		xf_profile_post
			WHERE		profile_post_id BETWEEN ? AND ?
			ORDER BY	profile_post_id";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.comment')->import($row['profile_post_id'], [
				'objectID' => $row['profile_user_id'],
				'userID' => $row['user_id'],
				'username' => $row['username'],
				'message' => self::fixComment($row['message']),
				'time' => $row['post_date']
			]);
		}
	}
	
	/**
	 * Counts wall responses.
	 */
	public function countWallResponses() {
		return $this->__getMaxID("xf_profile_post_comment", 'profile_post_comment_id');
	}
	
	/**
	 * Exports wall responses.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportWallResponses($offset, $limit) {
		$sql = "SELECT		*
			FROM		xf_profile_post_comment
			WHERE		profile_post_comment_id BETWEEN ? AND ?
			ORDER BY	profile_post_comment_id";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.comment.response')->import($row['profile_post_comment_id'], [
				'commentID' => $row['profile_post_id'],
				'time' => $row['comment_date'],
				'userID' => $row['user_id'],
				'username' => $row['username'],
				'message' => self::fixComment($row['message']),
			]);
		}
	}
	
	/**
	 * Counts user avatars.
	 */
	public function countUserAvatars() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	xf_user
			WHERE	avatar_date <> ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([0]);
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports user avatars.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportUserAvatars($offset, $limit) {
		$sql = "SELECT		user_id
			FROM		xf_user
			WHERE		avatar_date <> ?
			ORDER BY	user_id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute([0]);
		while ($row = $statement->fetchArray()) {
			$config = $this->getConfig();
			$location = $this->fileSystemPath.$config['externalDataPath'].'/avatars/l/'.floor($row['user_id'] / 1000).'/'.$row['user_id'].'.jpg';
			
			if (!$imageSize = @getimagesize($location)) continue;
			
			switch ($imageSize[2]) {
				case IMAGETYPE_JPEG:
					$extension = 'jpg';
				break;
				case IMAGETYPE_PNG:
					$extension = 'png';
				break;
				case IMAGETYPE_GIF:
					$extension = 'gif';
			}
			
			/** @noinspection PhpUndefinedVariableInspection */
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.avatar')->import($row['user_id'], [
				'avatarName' => '',
				'avatarExtension' => $extension,
				'userID' => $row['user_id']
			], ['fileLocation' => $location]);
		}
	}
	
	/**
	 * Counts conversation folders.
	 */
	public function countConversationFolders() {
		return $this->countUsers();
	}
	
	/**
	 * Exports conversation folders.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportConversationFolders($offset, $limit) {
		$sql = "SELECT		user_id
			FROM		xf_user
			ORDER BY	user_id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.label')->import($row['user_id'], [
				'userID' => $row['user_id'],
				'label' => 'Star'
			]);
		}
	}
	
	/**
	 * Counts conversations.
	 */
	public function countConversations() {
		return $this->__getMaxID("xf_conversation_master", 'conversation_id');
	}
	
	/**
	 * Exports conversations.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportConversations($offset, $limit) {
		$sql = "SELECT		*
			FROM		xf_conversation_master
			WHERE		conversation_id BETWEEN ? AND ?
			ORDER BY	conversation_id";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation')->import($row['conversation_id'], [
				'subject' => $row['title'],
				'time' => $row['start_date'],
				'userID' => $row['user_id'],
				'username' => $row['username'],
				'isDraft' => 0,
				'isClosed' => $row['conversation_open'] ? 0 : 1,
				'participantCanInvite' => $row['open_invite'] ? 1 : 0
			]);
		}
	}
	
	/**
	 * Counts conversation messages.
	 */
	public function countConversationMessages() {
		return $this->__getMaxID("xf_conversation_message", 'message_id');
	}
	
	/**
	 * Exports conversation messages.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportConversationMessages($offset, $limit) {
		$sql = "SELECT		message_table.*, INET_NTOA(ip_table.ip) AS ip
			FROM		xf_conversation_message message_table
			LEFT JOIN	xf_ip ip_table
			ON		message_table.ip_id = ip_table.ip_id
			WHERE		message_table.message_id BETWEEN ? AND ?
			ORDER BY	message_table.message_id";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.message')->import($row['message_id'], [
				'conversationID' => $row['conversation_id'],
				'userID' => $row['user_id'],
				'username' => $row['username'],
				'message' => self::fixBBCodes($row['message']),
				'time' => $row['message_date'],
				'ipAddress' => $row['ip'] ? UserUtil::convertIPv4To6($row['ip']) : ''
			]);
		}
	}
	
	/**
	 * Counts conversation recipients.
	 */
	public function countConversationUsers() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	xf_conversation_recipient";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports conversation recipients.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportConversationUsers($offset, $limit) {
		$sql = "SELECT		recipient_table.*, user_table.username, cuser_table.is_starred
			FROM		xf_conversation_recipient recipient_table
			LEFT JOIN	xf_user user_table
			ON		user_table.user_id = recipient_table.user_id
			LEFT JOIN	xf_conversation_user cuser_table
			ON		cuser_table.owner_user_id = recipient_table.user_id
				AND	cuser_table.conversation_id = recipient_table.conversation_id
			ORDER BY	recipient_table.conversation_id, recipient_table.user_id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.user')->import(0, [
				'conversationID' => $row['conversation_id'],
				'participantID' => $row['user_id'],
				'username' => $row['username'] ?: '',
				'hideConversation' => $row['recipient_state'] == 'deleted_ignored' ? Conversation::STATE_LEFT : ($row['recipient_state'] == 'deleted' ? Conversation::STATE_HIDDEN : Conversation::STATE_DEFAULT),
				'isInvisible' => 0,
				'lastVisitTime' => $row['last_read_date']
			], ['labelIDs' => $row['is_starred'] ? [$row['user_id']] : []]);
		}
	}
	
	/**
	 * Counts boards.
	 */
	public function countBoards() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	xf_node
			WHERE	node_type_id IN (?, ?, ?)";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(['Forum', 'Category', 'LinkForum']);
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports boards.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportBoards(/** @noinspection PhpUnusedParameterInspection */$offset, $limit) {
		$sql = "SELECT		node.node_id AS nodeID, node.*, forum.*, link_forum.*
			FROM		xf_node node
			LEFT JOIN	xf_forum forum
			ON		node.node_id = forum.node_id
			LEFT JOIN	xf_link_forum link_forum
			ON		node.node_id = link_forum.node_id
			WHERE		node_type_id IN (?, ?, ?)
			ORDER BY	node.lft";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(['Forum', 'Category', 'LinkForum']);
		
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.board')->import($row['nodeID'], [
				'parentID' => $row['parent_node_id'] ?: null,
				'position' => $row['lft'],
				'boardType' => $row['node_type_id'] == 'Category' ? Board::TYPE_CATEGORY : ($row['node_type_id'] == 'Forum' ? Board::TYPE_BOARD : Board::TYPE_LINK),
				'title' => $row['title'],
				'description' => $row['description'],
				'descriptionUseHtml' => 1, // cannot be disabled
				'externalURL' => $row['link_url'] ?: '',
				'countUserPosts' => $row['count_messages'] !== null ? $row['count_messages'] : 1,
				'isClosed' => $row['allow_posting'] ? 0 : 1,
				'isInvisible' => $row['display_in_list'] ? 0 : 1,
				'clicks' => $row['redirect_count'] ?: 0,
				'posts' => $row['message_count'] ?: 0,
				'threads' => $row['discussion_count'] ?: 0
			]);
		}
	}
	
	/**
	 * Counts threads.
	 */
	public function countThreads() {
		return $this->__getMaxID("xf_thread", 'thread_id');
	}
	
	/**
	 * Exports threads.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportThreads($offset, $limit) {
		$sql = "SELECT		*
			FROM		xf_thread
			WHERE		thread_id BETWEEN ? AND ?
			ORDER BY	thread_id";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			$data = [
				'boardID' => $row['node_id'],
				'topic' => $row['title'],
				'time' => $row['post_date'],
				'userID' => $row['user_id'],
				'username' => $row['username'],
				'views' => $row['view_count'],
				'isSticky' => $row['sticky'] ? 1 : 0,
				'isDisabled' => $row['discussion_state'] == 'moderated' ? 1 : 0,
				'isClosed' => $row['discussion_open'] ? 0 : 1,
				'isDeleted' => $row['discussion_state'] == 'deleted' ? 1 : 0,
				'deleteTime' => $row['discussion_state'] == 'deleted' ? TIME_NOW : 0
			];
			
			$additionalData = [];
			if ($row['prefix_id']) $additionalData['labels'] = [$row['node_id'].'-'.$row['prefix_id']];
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.thread')->import($row['thread_id'], $data, $additionalData);
		}
	}
	
	/**
	 * Counts posts.
	 */
	public function countPosts() {
		return $this->__getMaxID("xf_post", 'post_id');
	}
	
	/**
	 * Exports posts.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportPosts($offset, $limit) {
		$sql = "SELECT		post.*, user.username AS editor, INET_NTOA(ip.ip) AS ip, thread.title
			FROM		xf_post post
			LEFT JOIN	xf_user user
			ON		post.last_edit_user_id = user.user_id
			LEFT JOIN	xf_ip ip
			ON		post.ip_id = ip.ip_id
			LEFT JOIN	xf_thread thread
			ON		(thread.thread_id = post.thread_id AND thread.first_post_id = post.post_id)
			WHERE		post_id BETWEEN ? AND ?
			ORDER BY	post_id";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.post')->import($row['post_id'], [
				'threadID' => $row['thread_id'],
				'userID' => $row['user_id'],
				'username' => $row['username'],
				'subject' => $row['title'] ?: '',
				'message' => self::fixBBCodes($row['message']),
				'time' => $row['post_date'],
				'isDisabled' => $row['message_state'] == 'moderated' ? 1 : 0,
				'isDeleted' => $row['message_state'] == 'deleted' ? 1 : 0,
				'editorID' => $row['last_edit_user_id'] ?: null,
				'editor' => $row['editor'] ?: '',
				'lastEditTime' => $row['last_edit_date'],
				'editCount' => $row['editor'] ? $row['edit_count'] : 0,
				'ipAddress' => $row['ip'] ? UserUtil::convertIPv4To6($row['ip']) : ''
			]);
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
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportPostAttachments($offset, $limit) {
		$this->exportAttachments('post', 'com.woltlab.wbb.attachment', $offset, $limit);
	}
	
	/**
	 * Counts watched threads.
	 */
	public function countWatchedThreads() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	xf_thread_watch";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports watched threads.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportWatchedThreads($offset, $limit) {
		$sql = "SELECT		*
			FROM		xf_thread_watch
			ORDER BY	user_id, thread_id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.watchedThread')->import(0, [
				'objectID' => $row['thread_id'],
				'userID' => $row['user_id']
			]);
		}
	}
	
	/**
	 * Counts polls.
	 */
	public function countPolls() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	xf_poll
			WHERE	content_type = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(['thread']);
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports polls.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportPolls($offset, $limit) {
		$sql = "SELECT		poll.*, thread.first_post_id,
					(SELECT COUNT(*) FROM xf_poll_response response WHERE poll.poll_id = response.poll_id) AS responses
			FROM		xf_poll poll
			INNER JOIN	xf_thread thread
			ON		(poll.content_id = thread.thread_id)
			WHERE		content_type = ?
			ORDER BY	poll.poll_id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(['thread']);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll')->import($row['poll_id'], [
				'objectID' => $row['first_post_id'],
				'question' => $row['question'],
				'endTime' => $row['close_date'],
				'isChangeable' => 0,
				'isPublic' => $row['public_votes'] ? 1 : 0,
				'maxVotes' => isset($row['max_votes']) ? $row['max_votes'] : ($row['multiple'] ? $row['responses'] : 1),
				'votes' => $row['voter_count']
			]);
		}
	}
	
	/**
	 * Counts poll options.
	 */
	public function countPollOptions() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	xf_poll_response";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports poll options.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportPollOptions($offset, $limit) {
		$sql = "SELECT		*
			FROM		xf_poll_response
			ORDER BY	poll_response_id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll.option')->import($row['poll_response_id'], [
				'pollID' => $row['poll_id'],
				'optionValue' => $row['response'],
				'showOrder' => $row['poll_response_id'],
				'votes' => $row['response_vote_count']
			]);
		}
	}
	
	/**
	 * Counts poll option votes.
	 */
	public function countPollOptionVotes() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	xf_poll_vote";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports poll option votes.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportPollOptionVotes($offset, $limit) {
		$sql = "SELECT		*
			FROM		xf_poll_vote
			ORDER BY	poll_response_id, user_id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll.option.vote')->import(0, [
				'pollID' => $row['poll_id'],
				'optionID' => $row['poll_response_id'],
				'userID' => $row['user_id']
			]);
		}
	}
	
	/**
	 * Counts labels.
	 */
	public function countLabels() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	xf_forum_prefix";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports labels.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportLabels(/** @noinspection PhpUnusedParameterInspection */$offset, $limit) {
		$objectType = ObjectTypeCache::getInstance()->getObjectTypeByName('com.woltlab.wcf.label.objectType', 'com.woltlab.wbb.board');
		
		$sql = "SELECT		forum.*, phrase.phrase_text
			FROM		xf_forum_prefix forum
			LEFT JOIN	xf_phrase phrase
			ON		phrase.title = ('thread_prefix_' || forum.prefix_id)";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			// import label group
			if (!ImportHandler::getInstance()->getNewID('com.woltlab.wcf.label.group', $row['node_id'])) {
				ImportHandler::getInstance()->getImporter('com.woltlab.wcf.label.group')->import($row['node_id'], [
					'groupName' => 'labelgroup'.$row['node_id']
				], ['objects' => [$objectType->objectTypeID => [ImportHandler::getInstance()->getNewID('com.woltlab.wbb.board', $row['node_id'])]]]);
			}
			
			if (!ImportHandler::getInstance()->getNewID('com.woltlab.wcf.label', $row['node_id'].'-'.$row['prefix_id'])) {
				ImportHandler::getInstance()->getImporter('com.woltlab.wcf.label')->import($row['node_id'].'-'.$row['prefix_id'], [
					'groupID' => $row['node_id'],
					'label' => $row['phrase_text']
				]);
			}
		}
	}
	
	/**
	 * Counts ACLs.
	 */
	public function countACLs() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	xf_permission_entry_content
			WHERE		permission_group_id = ?
				AND	permission_value <> ?
				AND	content_type = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(['forum', 'use_int', 'node']);
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports ACLs.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportACLs($offset, $limit) {
		static $mapping = [
			'approveUnapprove' => ['canEnableThread', 'canEnablePost'],
			'deleteAnyPost' => ['canDeletePost'],
			'deleteAnyThread' => ['canDeleteThread'],
			'deleteOwnPost' => ['canDeleteOwnPost'],
			'editAnyPost' => ['canEditPost'],
			'editOwnPost' => ['canEditOwnPost'],
			'hardDeleteAnyPost' => ['canDeletePostCompletely'],
			'hardDeleteAnyThread' => ['canDeleteThreadCompletely'],
			'lockUnlockThread' => ['canCloseThread'],
			'manageAnyThread' => ['canMoveThread', 'canMergeThread'],
			'postReply' => ['canReplyThread'],
			'postThread' => ['canStartThread'],
			'stickUnstickThread' => ['canPinThread'],
			'undelete' => ['canRestorePost', 'canRestoreThread'],
			'uploadAttachments' => ['canUploadAttachment'],
			'viewAttachment' => ['canDownloadAttachment'],
			'viewContent' => ['canReadThread'],
			'viewDeleted' => ['canReadDeletedPost', 'canReadDeletedThread'],
			'votePoll' => ['canVotePoll']
		];
		
		$sql = "SELECT		*
			FROM		xf_permission_entry_content
			WHERE		permission_group_id = ?
				AND	permission_value <> ?
				AND	content_type = ?
			ORDER BY	permission_entry_id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(['forum', 'use_int', 'node']);
		while ($row = $statement->fetchArray()) {
			if (!isset($mapping[$row['permission_id']])) continue;
			
			foreach ($mapping[$row['permission_id']] as $permission) {
				ImportHandler::getInstance()->getImporter('com.woltlab.wbb.acl')->import(0, [
					'objectID' => $row['content_id'],
					$row['user_id'] ? 'userID' : 'groupID' => $row['user_id'] ?: $row['user_group_id'],
					'optionValue' => $row['permission_value'] == 'content_allow' ? 1 : 0
				], [
					'optionName' => $permission
				]);
			}
		}
	}
	
	
	/**
	 * Counts blog categories.
	 */
	public function countBlogCategories() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	xfa_blog_category
			WHERE	user_id = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([0]);
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports blog categories.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportBlogCategories($offset, $limit) {
		$sql = "SELECT		*
			FROM		xfa_blog_category
			WHERE		user_id = ?
			ORDER BY	category_id ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute([0]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.blog.category')->import($row['category_id'], [
				'title' => $row['category_name'],
				'parentCategoryID' => 0, // by intent as Community Blog does not support arbitrary nested categories
				'showOrder' => $row['display_order']
			]);
		}
	}
	
	/**
	 * Counts blog entries.
	 */
	public function countBlogEntries() {
		$sql = "SELECT
			(
				SELECT	COUNT(*)
				FROM	xfa_blog_entry
			)
			+
			(
				SELECT	COUNT(*)
				FROM	xfa_blog_entry_scheduled
			) AS count";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports blog entries.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportBlogEntries($offset, $limit) {
		$sql = "(
				SELECT		1 AS isPublished,
						entry.entry_id, entry.user_id, user.username, entry.title,
						entry.message, entry.post_date, entry.reply_count, entry.view_count,
						(SELECT GROUP_CONCAT(category_id) FROM xfa_blog_entry_category category WHERE category.entry_id = entry.entry_id) AS categories
				FROM		xfa_blog_entry entry
				LEFT JOIN	xf_user user
				ON		entry.user_id = user.user_id
			)
			UNION ALL
			(
				SELECT		0 AS isPublished,
						(entry.scheduled_entry_id || '-draft') AS entry_id, entry.user_id, user.username, entry.title,
						entry.message, entry.post_date, 0 AS reply_count, 0 AS view_count,
						entry.categories
				FROM		xfa_blog_entry_scheduled entry
				LEFT JOIN	xf_user user
				ON		entry.user_id = user.user_id
			)
			ORDER BY isPublished DESC, entry_id ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$additionalData = [];
			if ($row['isPublished']) {
				$additionalData['categories'] = explode(',', $row['categories']);
			}
			else {
				$categories = @unserialize($row['categories']);
				if ($categories) $additionalData['categories'] = $categories;
			}
			
			ImportHandler::getInstance()->getImporter('com.woltlab.blog.entry')->import($row['entry_id'], [
				'userID' => $row['user_id'] ?: null,
				'username' => $row['username'] ?: '',
				'subject' => $row['title'],
				'message' => self::fixBBCodes($row['message']),
				'time' => $row['post_date'],
				'comments' => $row['reply_count'],
				'views' => $row['view_count'],
				'isPublished' => $row['isPublished'],
				'publicationDate' => $row['post_date']
			], $additionalData);
		}
	}
	
	/**
	 * Counts blog comments.
	 */
	public function countBlogComments() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	xfa_blog_comment";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports blog comments.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportBlogComments($offset, $limit) {
		$sql = "SELECT		comment.*, user.username
			FROM		xfa_blog_comment comment
			LEFT JOIN	xf_user user
			ON		comment.user_id = user.user_id
			ORDER BY	comment.comment_id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.blog.entry.comment')->import($row['comment_id'], [
				'objectID' => $row['entry_id'],
				'userID' => $row['user_id'] ?: null,
				'username' => $row['username'] ?: '',
				'message' => self::fixComment($row['message']),
				'time' => $row['post_date']
			]);
		}
	}
	
	/**
	 * Counts blog entry likes.
	 */
	public function countBlogEntryLikes() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	xf_liked_content
			WHERE	content_type = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(['xfa_blog_entry']);
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports blog entry likes.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportBlogEntryLikes($offset, $limit) {
		$sql = "SELECT		*
			FROM		xf_liked_content
			WHERE		content_type = ?
			ORDER BY	like_id ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(['xfa_blog_entry']);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.blog.entry.like')->import($row['like_id'], [
				'objectID' => $row['content_id'],
				'objectUserID' => $row['content_user_id'] ?: null,
				'userID' => $row['like_user_id'],
				'likeValue' => Like::LIKE,
				'time' => $row['like_date']
			]);
		}
	}
	
	/**
	 * Returns the number of blog attachments.
	 * 
	 * @return	integer
	 */
	public function countBlogAttachments() {
		return $this->countAttachments('xfa_blog_entry');
	}
	
	/**
	 * Exports blog attachments.
	 * 
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportBlogAttachments($offset, $limit) {
		$this->exportAttachments('xfa_blog_entry', 'com.woltlab.blog.entry.attachment', $offset, $limit);
	}
	
	/**
	 * Returns the number of attachments.
	 * 
	 * @param	string		$type
	 * @return	integer
	 */
	public function countAttachments($type) {
		$sql = "SELECT	COUNT(*) AS count
		FROM	xf_attachment
		WHERE	content_type = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$type]);
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports attachments.
	 * 
	 * @param	string		$type
	 * @param	string		$objectType
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportAttachments($type, $objectType, $offset, $limit) {
		$sql = "SELECT		attachment.*, data.*
		FROM		xf_attachment attachment
		LEFT JOIN	xf_attachment_data data
		ON		attachment.data_id = data.data_id
		WHERE		attachment.content_type = ?
		ORDER BY	attachment.attachment_id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute([$type]);
		while ($row = $statement->fetchArray()) {
			$config = self::getConfig();
			$fileLocation = $this->fileSystemPath.$config['internalDataPath'].'/attachments/'.floor($row['data_id'] / 1000).'/'.$row['data_id'].'-'.$row['file_hash'].'.data';
			
			if (!file_exists($fileLocation)) continue;
			
			if ($imageSize = @getimagesize($fileLocation)) {
				$row['isImage'] = 1;
				$row['width'] = $imageSize[0];
				$row['height'] = $imageSize[1];
			}
			else {
				$row['isImage'] = $row['width'] = $row['height'] = 0;
			}
			
			ImportHandler::getInstance()->getImporter($objectType)->import($row['attachment_id'], [
				'objectID' => $row['content_id'],
				'userID' => $row['user_id'] ?: null,
				'filename' => $row['filename'],
				'filesize' => $row['file_size'],
				'fileType' => FileUtil::getMimeType($fileLocation) ?: 'application/octet-stream',
				'isImage' => $row['isImage'],
				'width' => $row['width'],
				'height' => $row['height'],
				'downloads' => $row['view_count'],
				'uploadTime' => $row['upload_date']
			], ['fileLocation' => $fileLocation]);
		}
	}
	
	/**
	 * Returns the configuration data of the imported board.
	 * 
	 * @return	array
	 */
	public function getConfig() {
		$config = [
			'db' => [
				'adapter' => 'mysqli',
				'host' => 'localhost',
				'port' => '3306',
				'username' => '',
				'password' => '',
				'dbname' => '',
				'adapterNamespace' => 'Zend_Db_Adapter'
			],
			'cache' => [
				'enabled' => false,
				'cacheSessions' => false,
				'frontend' => 'core',
				'frontendOptions' => [
					'caching' => true,
					'cache_id_prefix' => 'xf_'
				],
				'backend' => 'file',
				'backendOptions' => [
					'file_name_prefix' => 'xf_'
				]
			],
			'debug' => false,
			'enableListeners' => true,
			'development' => [
				'directory' => '',
				'default_addon' => ''
			],
			'superAdmins' => '1',
			'globalSalt' => '1717c7e013ff20562bcc1483c1e0c8a8',
			'jsVersion' => '',
			'cookie' => [
				'prefix' => 'xf_',
				'path' => '/',
				'domain' => ''
			],
			'enableMail' => true,
			'enableMailQueue' => true,
			'internalDataPath' => 'internal_data',
			'externalDataPath' => 'data',
			'externalDataUrl' => 'data',
			'javaScriptUrl' => 'js',
			'checkVersion' => true,
			'enableGzip' => true,
			'enableContentLength' => true,
			'adminLogLength' => 60,
			'chmodWritableValue' => 0,
			'rebuildMaxExecution' => 10,
			'passwordIterations' => 10,
			'enableTemplateModificationCallbacks' => true,
			'enableClickjackingProtection' => true,
			'maxImageResizePixelCount' => 20000000
		];
		/** @noinspection PhpIncludeInspection */
		require($this->fileSystemPath.'library/config.php');
		
		return $config;
	}
	
	/**
	 * Returns message with fixed BBCodes as used in WCF.
	 *
	 * @param	string		$message
	 * @return	string
	 */
	private static function fixBBCodes($message) {
		static $mediaRegex = null;
		static $mediaCallback = null;
		static $userRegex = null;
		static $userCallback = null;
		static $quoteRegex = null;
		static $quoteCallback = null;
		
		if ($mediaRegex === null) {
			$mediaRegex = new Regex('\[media=(youtube|vimeo|dailymotion)\]([a-zA-Z0-9_-]+)', Regex::CASE_INSENSITIVE);
			$mediaCallback = function ($matches) {
				switch ($matches[1]) {
					case 'youtube':
						$url = 'https://www.youtube.com/watch?v='.$matches[2];
					break;
					case 'vimeo':
						$url = 'http://vimeo.com/'.$matches[2];
					break;
					case 'dailymotion':
						$url = 'http://dailymotion.com/video/'.$matches[2];
					break;
				}
				
				/** @noinspection PhpUndefinedVariableInspection */
				return '[media]'.$url;
			};
			
			$userRegex = new Regex('\[user=(\d+)\](.*?)\[/user\]', Regex::CASE_INSENSITIVE);
			$userCallback = function ($matches) {
				$userLink = LinkHandler::getInstance()->getLink('User', [
					'userID' => $matches[1],
					'forceFrontend' => true
				]);
				
				$userLink = str_replace(["\\", "'"], ["\\\\", "\'"], $userLink);
				
				return "[url='".$userLink."']".$matches[2]."[/url]";
			};
			
			$quoteRegex = new Regex('\[quote=("?)(?P<username>[^,\]\n]*)(?:, post: (?P<postID>\d+)(?:, member: \d+)?)?\1\]', Regex::CASE_INSENSITIVE);
			$quoteCallback = function ($matches) {
				if (isset($matches['username']) && $matches['username']) {
					$username = str_replace(["\\", "'"], ["\\\\", "\'"], $matches['username']);
					
					if (isset($matches['postID']) && $matches['postID']) {
						$postLink = LinkHandler::getInstance()->getLink('Thread', [
							'application' => 'wbb',
							'postID' => $matches['postID'],
							'forceFrontend' => true
							]).'#post'.$matches['postID'];
						$postLink = str_replace(["\\", "'"], ["\\\\", "\'"], $postLink);
						
						return "[quote='".$username."','".$postLink."']";
					}
					
					return "[quote='".$username."']";
				}
				return "[quote]";
			};
		}
		
		$message = $mediaRegex->replace($message, $mediaCallback);
		$message = $userRegex->replace($message, $userCallback);
		$message = $quoteRegex->replace($message, $quoteCallback);
		
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
		
		static $map = [
			'[php]' => '[code=php]',
			'[/php]' => '[/code]',
			'[html]' => '[code=html]',
			'[/html]' => '[/code]',
			'[center]' => '[align=center]',
			'[/center]' => '[/align]',
			'[right]' => '[align=right]',
			'[/right]' => '[/align]',
			'[attach=full]' => '[attach]'
		];
		
		// use proper WCF 2 bbcode
		$message = str_ireplace(array_keys($map), array_values($map), $message);
		
		// remove crap
		$message = MessageUtil::stripCrap($message);
		
		return $message;
	}
	
	/**
	 * Returns comment text with fixed formatting as used in WCF.
	 * 
	 * @param	string		$message
	 * @return	string
	 */
	private static function fixComment($message) {
		static $mentionRegex = null;
		if ($mentionRegex === null) {
			$mentionRegex = new Regex('@\[\d+:(@[^\]]+)\]');
		}
		
		return $mentionRegex->replace($message, "\\1");
	}
}
