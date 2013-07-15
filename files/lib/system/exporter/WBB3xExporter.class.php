<?php
namespace wcf\system\exporter;
use wcf\data\like\Like;
use wcf\data\object\type\ObjectTypeCache;
use wcf\data\user\option\UserOption;
use wcf\system\database\util\PreparedStatementConditionBuilder;
use wcf\system\database\DatabaseException;
use wcf\system\exception\SystemException;
use wcf\system\importer\ImportHandler;
use wcf\system\WCF;
use wcf\util\StringUtil;

/**
 * Exporter for Burning Board 3.x
 *
 * @author	Marcel Werk
 * @copyright	2001-2012 WoltLab GmbH
 * @license	WoltLab Burning Board License <http://www.woltlab.com/products/burning_board/license.php>
 * @package	com.woltlab.wcf.exporter
 * @subpackage	system.exporter
 * @category	Community Framework (commercial)
 */
class WBB3xExporter extends AbstractExporter {
	/**
	 * wcf installation number
	 * @var integer
	 */
	protected $dbNo = 0;
	
	/**
	 * wbb installation number
	 * @var integer
	 */
	protected $instanceNo = 0;
	
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
		'com.woltlab.wcf.smiley' => 'Smilies'
	);
	
	/**
	 * @see wcf\system\exporter\AbstractExporter::$limits
	 */
	protected $limits = array(
		'com.woltlab.wcf.user' => 200,
		'com.woltlab.wcf.user.avatar' => 100,
		'com.woltlab.wcf.conversation.attachment' => 100,
		'com.woltlab.wbb.thread' => 200,
		'com.woltlab.wbb.attachment' => 100,
		'com.woltlab.wbb.acl' => 50
	);
	
	/**
	 * @see wcf\system\exporter\IExporter::init()
	 */
	public function init() {
		parent::init();
		
		if (preg_match('/^wbb(\d)_(\d)_$/', $this->databasePrefix, $match)) {
			$this->dbNo = $match[1];
			$this->instanceNo = $match[2];
		}
	}
	
	/**
	 * @see wcf\system\exporter\IExporter::getSupportedData()
	 */
	public function getSupportedData() {
		return array(
			'com.woltlab.wcf.user' => array(
				'com.woltlab.wcf.user.group',
				'com.woltlab.wcf.user.avatar',
				'com.woltlab.wcf.user.option',
				'com.woltlab.wcf.user.comment',
				'com.woltlab.wcf.user.follower',
				'com.woltlab.wcf.user.rank'
			),
			'com.woltlab.wbb.board' => array(
				'com.woltlab.wbb.acl',
				'com.woltlab.wbb.attachment',
				'com.woltlab.wbb.poll',
				'com.woltlab.wbb.watchedThread',
				'com.woltlab.wbb.like',
				'com.woltlab.wcf.label'
			),
			'com.woltlab.wcf.conversation' => array(
				'com.woltlab.wcf.conversation.attachment',
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
		
		$sql = "SELECT COUNT(*) FROM wbb".$this->dbNo."_".$this->instanceNo."_post";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
	}
	
	/**
	 * @see wcf\system\exporter\IExporter::validateFileAccess()
	 */
	public function validateFileAccess() {
		if (in_array('com.woltlab.wcf.user.avatar', $this->selectedData) || in_array('com.woltlab.wbb.attachment', $this->selectedData) || in_array('com.woltlab.wcf.conversation.attachment', $this->selectedData)) {
			if (empty($this->fileSystemPath) || !@file_exists($this->fileSystemPath . 'lib/core.functions.php')) return false;
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
			if (in_array('com.woltlab.wcf.user.option', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.option';
			$queue[] = 'com.woltlab.wcf.user';
			if (in_array('com.woltlab.wcf.user.avatar', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.avatar';
			
			if ($this->searchPlugin('com.woltlab.wcf.user.guestbook')) {
				if (in_array('com.woltlab.wcf.user.comment', $this->selectedData)) {
					$queue[] = 'com.woltlab.wcf.user.comment';
					$queue[] = 'com.woltlab.wcf.user.comment.response';
				}
			}
			
			if (in_array('com.woltlab.wcf.user.follower', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.follower';
			
			// conversation
			if (in_array('com.woltlab.wcf.conversation', $this->selectedData)) {
				if (in_array('com.woltlab.wcf.conversation.label', $this->selectedData)) $queue[] = 'com.woltlab.wcf.conversation.label';
				
				$queue[] = 'com.woltlab.wcf.conversation';
				$queue[] = 'com.woltlab.wcf.conversation.message';
				$queue[] = 'com.woltlab.wcf.conversation.user';
					
				if (in_array('com.woltlab.wcf.conversation.attachment', $this->selectedData)) $queue[] = 'com.woltlab.wcf.conversation.attachment';
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
			if (in_array('com.woltlab.wbb.like', $this->selectedData)) $queue[] = 'com.woltlab.wbb.like';
		}
		
		// smiley
		if (in_array('com.woltlab.wcf.smiley', $this->selectedData)) $queue[] = 'com.woltlab.wcf.smiley';
		
		return $queue;
	}
	
	/**
	 * @see wcf\system\exporter\IExporter::getDefaultDatabasePrefix()
	 */
	public function getDefaultDatabasePrefix() {
		return 'wbb1_1_';
	}
	
	/**
	 * Counts user groups.
	 */
	public function countUserGroups() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_group
			WHERE	groupType > ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(3));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports user groups.
	 */
	public function exportUserGroups($offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_group
			WHERE		groupType > ?
			ORDER BY	groupID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(3));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.group')->import($row['groupID'], array(
				'groupName' => $row['groupName'],
				'groupType' => $row['groupType'],
				'userOnlineMarking' => $row['userOnlineMarking'],
				'showOnTeamPage' => $row['showOnTeamPage']
			));
		}
	}

	/**
	 * Counts users.
	 */
	public function countUsers() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_user";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports users.
	 */
	public function exportUsers($offset, $limit) {
		// cache existing user options
		$existingUserOptions = array();
		$sql = "SELECT	optionName, optionID
			FROM	wcf".WCF_N."_user_option
			WHERE	optionName NOT LIKE 'option%'";
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$existingUserOptions[$row['optionName']] = true;
		}
		
		// cache user options
		$userOptions = array();
		$sql = "SELECT	optionName, optionID
			FROM	wcf".$this->dbNo."_user_option";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$userOptions[$row['optionID']] = (isset($existingUserOptions[$row['optionName']]) ? $row['optionName'] : $row['optionID']);
		}
		
		// prepare password update
		$sql = "UPDATE	wcf".WCF_N."_user
			SET	password = ?
			WHERE	userID = ?";
		$passwordUpdateStatement = WCF::getDB()->prepareStatement($sql);
		
		// get users
		$sql = "SELECT		user_option_value.*, user_table.*,
					(
						SELECT	GROUP_CONCAT(groupID)
						FROM 	wcf".$this->dbNo."_user_to_groups
						WHERE 	userID = user_table.userID
					) AS groupIDs
			FROM		wcf".$this->dbNo."_user user_table
			LEFT JOIN	wcf".$this->dbNo."_user_option_value user_option_value
			ON		(user_option_value.userID = user_table.userID)
			ORDER BY	user_table.userID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		
		WCF::getDB()->beginTransaction();
		while ($row = $statement->fetchArray()) {
			$data = array(
				'username' => $row['username'],
				'password' => '',
				'email' => $row['email'],
				'registrationDate' => $row['registrationDate'],
				'banned' => $row['banned'],
				'banReason' => $row['banReason'],
				'activationCode' => $row['activationCode'],
				'oldUsername' => $row['oldUsername'],
				'registrationIpAddress' => $row['registrationIpAddress'],
				'disableAvatar' => $row['disableAvatar'],
				'disableAvatarReason' => $row['disableAvatarReason'],
				'enableGravatar' => ($row['gravatar'] == $row['email'] ? 1 : 0),
				'signature' => $row['signature'],
				'signatureEnableBBCodes' => $row['enableSignatureBBCodes'],
				'signatureEnableHtml' => $row['enableSignatureHtml'],
				'signatureEnableSmilies' => $row['enableSignatureSmilies'],
				'disableSignature' => $row['disableSignature'],
				'disableSignatureReason' => $row['disableSignatureReason'],
				'profileHits' => $row['profileHits'],
				'userTitle' => $row['userTitle'],
				'lastActivityTime' => $row['lastActivityTime']
				
			);
			$additionalData = array(
				'groupIDs' => explode(',', $row['groupIDs']),
				'options' => array()
			);
			
			// handle user options
			foreach ($userOptions as $optionID => $optionName) {
				if ($optionName == 'timezone') continue; // skip broken timezone setting
				
				if (isset($row['userOption'.$optionID])) {
					$additionalData['options'][$optionName] = $row['userOption'.$optionID];
				}
			}
			
			// import user
			$newUserID = ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user')->import($row['userID'], $data, $additionalData);
			
			// update password hash
			if ($newUserID) {
				$passwordUpdateStatement->execute(array('wcf1:'.$row['password'].':'.$row['salt'], $newUserID));
			}
		}
		WCF::getDB()->commitTransaction();
	}
	
	/**
	 * Counts user ranks.
	 */
	public function countUserRanks() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_user_rank";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports user ranks.
	 */
	public function exportUserRanks($offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_user_rank
			ORDER BY	rankID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.rank')->import($row['rankID'], array(
				'groupID' => $row['groupID'],
				'requiredPoints' => $row['neededPoints'],
				'rankTitle' => $row['rankTitle'],
				'rankImage' => $row['rankImage'],
				'repeatImage' => $row['repeatImage'],
				'requiredGender' => $row['gender']
			));
		}
	}
	
	/**
	 * Counts followers.
	 */
	public function countFollowers() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_user_whitelist";
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
			FROM		wcf".$this->dbNo."_user_whitelist
			ORDER BY	userID, whiteUserID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.follower')->import(0, array(
				'userID' => $row['userID'],
				'followUserID' => $row['whiteUserID'],
				'time' => $row['time']
			));
		}
	}
	
	/**
	 * Counts guestbook entries.
	 */
	public function countGuestbookEntries() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_user_guestbook";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports guestbook entries.
	 */
	public function exportGuestbookEntries($offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_user_guestbook
			ORDER BY	entryID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.comment')->import($row['entryID'], array(
				'objectID' => $row['ownerID'],
				'userID' => $row['userID'],
				'username' => $row['username'],
				'message' => $row['message'],
				'time' => $row['time']
			));
		}
	}
	
	/**
	 * Counts guestbook responses.
	 */
	public function countGuestbookResponses() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_user_guestbook
			WHERE	commentTime > ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(0));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports guestbook responses.
	 */
	public function exportGuestbookResponses($offset, $limit) {
		$sql = "SELECT		user_guestbook.*, user_table.username AS ownerName
			FROM		wcf".$this->dbNo."_user_guestbook user_guestbook
			LEFT JOIN	wcf".$this->dbNo."_user user_table
			ON 		(user_table.userID = user_guestbook.ownerID)
			WHERE		user_guestbook.commentTime > ?
			ORDER BY	user_guestbook.entryID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(0));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.comment.response')->import($row['entryID'], array(
				'commentID' => $row['entryID'],
				'time' => $row['commentTime'],
				'userID' => $row['ownerID'],
				'username' => $row['ownerName'],
				'message' => $row['comment'],
			));
		}
	}
	
	/**
	 * Counts user avatars.
	 */
	public function countUserAvatars() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_avatar
			WHERE	userID <> ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(0));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports user avatars.
	 */
	public function exportUserAvatars($offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_avatar
			WHERE		userID <> ?
			ORDER BY	avatarID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(0));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.avatar')->import($row['avatarID'], array(
				'avatarName' => $row['avatarName'],
				'avatarExtension' => $row['avatarExtension'],
				'width' => $row['width'],
				'height' => $row['height'],
				'userID' => $row['userID']
			), array('fileLocation' => $this->fileSystemPath . 'images/avatars/avatar-' . $row['avatarID'] . '.' . $row['avatarExtension']));
		}
	}
	
	/**
	 * Counts user options.
	 */
	public function countUserOptions() {
		// get existing option names
		$optionsNames = $this->getExistingUserOptions();
		
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('categoryName LIKE ?', array('profile%'));
		if (!empty($optionsNames)) $conditionBuilder->add('optionName NOT IN (?)', array($optionsNames));
		
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_user_option
			".$conditionBuilder;
		$statement = $this->database->prepareStatement($sql);
		$statement->execute($conditionBuilder->getParameters());
		$row = $statement->fetchArray();
		return ($row['count'] ? 1 : 0);
	}
	
	/**
	 * Exports user options.
	 */
	public function exportUserOptions($offset, $limit) {
		// get existing option names
		$optionsNames = $this->getExistingUserOptions();
		
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('categoryName LIKE ?', array('profile%'));
		if (!empty($optionsNames)) $conditionBuilder->add('optionName NOT IN (?)', array($optionsNames));
		
		$sql = "SELECT 	user_option.*, (
					SELECT	languageItemValue
					FROM	wcf".$this->dbNo."_language_item
					WHERE	languageItem = CONCAT('wcf.user.option.', user_option.optionName)
						AND languageID = (
							SELECT	languageID
							FROM	wcf".$this->dbNo."_language
							WHERE	isDefault = 1
						)
					LIMIT	1
				) AS name
			FROM	wcf".$this->dbNo."_user_option user_option
			".$conditionBuilder;
		$statement = $this->database->prepareStatement($sql);
		$statement->execute($conditionBuilder->getParameters());
		while ($row = $statement->fetchArray()) {
			$editable = 0;
			switch ($row['editable']) {
				case 0:
					$editable = UserOption::EDITABILITY_ALL;
					break;
				case 1:
				case 2:
					$editable = UserOption::EDITABILITY_OWNER;
					break;
				case 3:
					$editable = UserOption::EDITABILITY_ADMINISTRATOR;
					break;
			}
			
			$visible = 0;
			switch ($row['visible']) {
				case 0:
					$visible = UserOption::VISIBILITY_ALL;
					break;
				case 1:
					$visible = UserOption::VISIBILITY_OWNER | UserOption::VISIBILITY_ADMINISTRATOR;
					break;
				case 2:
					$visible = UserOption::VISIBILITY_OWNER;
					break;
				case 3:
					$visible = UserOption::VISIBILITY_ADMINISTRATOR;
					break;
			}
			
			// fix option types
			switch ($row['optionType']) {
				case 'multiselect':
					$row['optionType'] = 'multiSelect';
					break;
				case 'radiobuttons':
					$row['optionType'] = 'radioButton';
					break;
					
				case 'birthday':
				case 'boolean':
				case 'date':
				case 'integer':
				case 'float':
				case 'password':
				case 'select':
				case 'text':
				case 'textarea':
				case 'message':
					break;
				
				default:
					$row['optionType'] = 'textarea';
			}
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.option')->import($row['optionID'], array(
				'categoryName' => $row['categoryName'],
				'optionType' => $row['optionType'],
				'defaultValue' => $row['defaultValue'],
				'validationPattern' => $row['validationPattern'],
				'selectOptions' => $row['selectOptions'],
				'required' => $row['required'],
				'askDuringRegistration' => $row['askDuringRegistration'],
				'searchable' => $row['searchable'],
				'isDisabled' => $row['disabled'],
				'editable' => $editable,
				'visible' => $visible
			), array('name' => ($row['name'] ?: $row['optionName'])));
		}
	}
	
	/**
	 * Counts conversation folders.
	 */
	public function countConversationFolders() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_pm_folder";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports conversation folders.
	 */
	public function exportConversationFolders($offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_pm_folder
			ORDER BY	folderID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$cssClassName = '';
			switch ($row['color']) {
				case 'yellow':
				case 'red':
				case 'blue':
				case 'green':
					$cssClassName = $row['color'];
					break;
			}
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.label')->import($row['folderID'], array(
				'userID' => $row['userID'],
				'label' => $row['folderName'],
				'cssClassName' => $cssClassName
			));
		}
	}
	
	/**
	 * Counts conversations.
	 */
	public function countConversations() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_pm
			WHERE	parentPmID = ?
				OR pmID = parentPmID";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(0));
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
			FROM		wcf".$this->dbNo."_pm
			WHERE		parentPmID = ?
					OR pmID = parentPmID
			ORDER BY	pmID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(0));
		while ($row = $statement->fetchArray()) {
			$conversationID = ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation')->import($row['pmID'], array(
				'subject' => $row['subject'],
				'time' => $row['time'],
				'userID' => $row['userID'],
				'username' => $row['username'],
				'isDraft' => $row['isDraft']
			));
			
			// add author
			$insertStatement->execute(array(
				$conversationID,
				ImportHandler::getInstance()->getNewID('com.woltlab.wcf.user', $row['userID']),
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
			FROM	wcf".$this->dbNo."_pm";
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
			FROM		wcf".$this->dbNo."_pm
			ORDER BY	pmID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.message')->import($row['pmID'], array(
				'conversationID' => ($row['parentPmID'] ?: $row['pmID']),
				'userID' => $row['userID'],
				'username' => $row['username'],
				'message' => self::fixBBCodes($row['message']),
				'time' => $row['time'],
				'attachments' => $row['attachments'],
				'enableSmilies' => $row['enableSmilies'],
				'enableHtml' => $row['enableHtml'],
				'enableBBCodes' => $row['enableBBCodes'],
				'showSignature' => $row['showSignature']
			));
		}
	}
	
	/**
	 * Counts conversation recipients.
	 */
	public function countConversationUsers() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_pm_to_user";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports conversation recipients.
	 */
	public function exportConversationUsers($offset, $limit) {
		$sql = "SELECT		pm_to_user.*, pm.parentPmID
			FROM		wcf".$this->dbNo."_pm_to_user pm_to_user
			LEFT JOIN	wcf".$this->dbNo."_pm pm
			ON		(pm.pmID = pm_to_user.pmID)
			ORDER BY	pm_to_user.pmID DESC, pm_to_user.recipientID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.user')->import(0, array(
				'conversationID' => ($row['parentPmID'] ?: $row['pmID']),
				'participantID' => $row['recipientID'],
				'hideConversation' => $row['isDeleted'],
				'isInvisible' => $row['isBlindCopy'],
				'lastVisitTime' => $row['isViewed']
			), array('labelIDs' => ($row['folderID'] ? array($row['folderID']) : array())));
		}
	}
	
	/**
	 * Counts conversation attachments.
	 */
	public function countConversationAttachments() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_attachment
			WHERE	containerType = ?
				AND containerID > ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array('pm', 0));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports conversation attachments.
	 */
	public function exportConversationAttachments($offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_attachment
			WHERE		containerType = ?
					AND containerID > ?
			ORDER BY	attachmentID DESC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array('pm', 0));
		while ($row = $statement->fetchArray()) {
			$fileLocation = $this->fileSystemPath.'attachments/attachment-'.$row['attachmentID'];
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.attachment')->import($row['attachmentID'], array(
				'objectID' => $row['containerID'],
				'userID' => ($row['userID'] ?: null),
				'filename' => $row['attachmentName'],
				'filesize' => $row['attachmentSize'],
				'fileType' => $row['fileType'],
				'isImage' => $row['isImage'],
				'width' => $row['width'],
				'height' => $row['height'],
				'downloads' => $row['downloads'],
				'lastDownloadTime' => $row['lastDownloadTime'],
				'uploadTime' => $row['uploadTime'],
				'showOrder' => $row['showOrder']
			), array('fileLocation' => $fileLocation));
		}
	}
	
	/**
	 * Counts boards.
	 */
	public function countBoards() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wbb".$this->dbNo."_".$this->instanceNo."_board";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return ($row['count'] ? 1 : 0);
	}
	
	/**
	 * Exports boards.
	 */
	public function exportBoards($offset, $limit) {
		$sql = "SELECT		board.*, structure.position
			FROM		wbb".$this->dbNo."_".$this->instanceNo."_board board
			LEFT JOIN	wbb".$this->dbNo."_".$this->instanceNo."_board_structure structure
			ON		(structure.boardID = board.boardID)	
			ORDER BY	board.parentID, structure.position";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$this->boardCache[$row['parentID']][] = $row;
		}
		
		$this->exportBoardsRecursively();
	}
	
	/**
	 * Exports the boards recursively.
	 */
	protected function exportBoardsRecursively($parentID = 0) {
		if (!isset($this->boardCache[$parentID])) return;
	
		foreach ($this->boardCache[$parentID] as $board) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.board')->import($board['boardID'], array(
				'parentID' => ($board['parentID'] ?: null),
				'position' => $board['position'],
				'boardType' => $board['boardType'],
				'title' => $board['title'],
				'description' => $board['description'],
				'descriptionUseHtml' => $board['allowDescriptionHtml'],
				'externalURL' => $board['externalURL'],
				'time' => $board['time'],
				'countUserPosts' => $board['countUserPosts'],
				'daysPrune' => $board['daysPrune'],
				'enableMarkingAsDone' => $board['enableMarkingAsDone'],
				'ignorable' => $board['ignorable'],
				'isClosed' => $board['isClosed'],
				'isInvisible' => $board['isInvisible'],
				'postSortOrder' => $board['postSortOrder'],
				'postsPerPage' => $board['postsPerPage'],
				'searchable' => $board['searchable'],
				'searchableForSimilarThreads' => $board['searchableForSimilarThreads'],
				'showSubBoards' => $board['showSubBoards'],
				'sortField' => $board['sortField'],
				'sortOrder' => $board['sortOrder'],
				'threadsPerPage' => $board['threadsPerPage'],
				'clicks' => $board['clicks'],
				'posts' => $board['posts'],
				'threads' => $board['threads']
			));
				
			$this->exportBoardsRecursively($board['boardID']);
		}
	}
	
	/**
	 * Counts threads.
	 */
	public function countThreads() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wbb".$this->dbNo."_".$this->instanceNo."_thread";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports threads.
	 */
	public function exportThreads($offset, $limit) {
		// get global prefixes
		$globalPrefixes = '';
		$sql = "SELECT	optionValue
			FROM	wcf".$this->dbNo."_option
			WHERE	optionName = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array('thread_default_prefixes'));
		$row = $statement->fetchArray();
		if ($row !== false) $globalPrefixes = $row['optionValue'];
		
		// get boards
		$boardPrefixes = array();
		$sql = "SELECT		boardID, prefixes, prefixMode
			FROM		wbb".$this->dbNo."_".$this->instanceNo."_board
			WHERE		prefixMode > ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(0));
		while ($row = $statement->fetchArray()) {
			$prefixes = '';
				
			switch ($row['prefixMode']) {
				case 1:
					$prefixes = $globalPrefixes;
					break;
				case 2:
					$prefixes = $row['prefixes'];
					break;
				case 3:
					$prefixes = $globalPrefixes . "\n" . $row['prefixes'];
					break;
			}
				
			$prefixes = StringUtil::trim(StringUtil::unifyNewlines($prefixes));
			if ($prefixes) {
				$key = StringUtil::getHash($prefixes);
				$boardPrefixes[$row['boardID']] = $key;
			}
		}
		
		
		// get thread ids
		$threadIDs = $announcementIDs = array();
		$sql = "SELECT		threadID, isAnnouncement
			FROM		wbb".$this->dbNo."_".$this->instanceNo."_thread
			ORDER BY	threadID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$threadIDs[] = $row['threadID'];
			if ($row['isAnnouncement']) $announcementIDs[] = $row['threadID'];
		}
	
		// get assigned boards (for announcements)
		$assignedBoards = array();
		if (!empty($announcementIDs)) {
			$conditionBuilder = new PreparedStatementConditionBuilder();
			$conditionBuilder->add('threadID IN (?)', array($announcementIDs));
			
			$sql = "SELECT		boardID, threadID
				FROM		wbb".$this->dbNo."_".$this->instanceNo."_thread_announcement
				".$conditionBuilder;
			$statement = $this->database->prepareStatement($sql);
			$statement->execute($conditionBuilder->getParameters());
			while ($row = $statement->fetchArray()) {
				if (!isset($assignedBoards[$row['threadID']])) $assignedBoards[$row['threadID']] = array();
				$assignedBoards[$row['threadID']][] = $row['boardID'];
			}
		}
		
		// get threads
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('threadID IN (?)', array($threadIDs));
		
		$sql = "SELECT		thread.*, language.languageCode
			FROM		wbb".$this->dbNo."_".$this->instanceNo."_thread thread
			LEFT JOIN	wcf".$this->dbNo."_language language
			ON		(language.languageID = thread.languageID)
			".$conditionBuilder;
		$statement = $this->database->prepareStatement($sql);
		$statement->execute($conditionBuilder->getParameters());
		while ($row = $statement->fetchArray()) {
			$data = array(
				'boardID' => $row['boardID'],
				'topic' => $row['topic'],
				'time' => $row['time'],
				'userID' => $row['userID'],
				'username' => $row['username'],
				'views' => $row['views'],
				'isAnnouncement' => $row['isAnnouncement'],
				'isSticky' => $row['isSticky'],
				'isDisabled' => $row['isDisabled'],
				'isClosed' => $row['isClosed'],
				'isDeleted' => $row['isDeleted'],
				'movedThreadID' => ($row['movedThreadID'] ?: null),
				'movedTime' => $row['movedTime'],
				'isDone' => $row['isDone'],
				'deleteTime' => $row['deleteTime']
			);
			$additionalData = array();
			if ($row['languageCode']) $additionalData['languageCode'] = $row['languageCode'];
			if (!empty($assignedBoards[$row['threadID']])) $additionalData['assignedBoards'] = $assignedBoards[$row['threadID']];
			if ($row['prefix'] && isset($boardPrefixes[$row['boardID']])) $additionalData['labels'] = array($boardPrefixes[$row['boardID']].'-'.$row['prefix']);
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.thread')->import($row['threadID'], $data, $additionalData);
		}
	}
	
	/**
	 * Counts posts.
	 */
	public function countPosts() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wbb".$this->dbNo."_".$this->instanceNo."_post";
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
			FROM		wbb".$this->dbNo."_".$this->instanceNo."_post
			ORDER BY	postID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.post')->import($row['postID'], array(
				'threadID' => $row['threadID'],
				'userID' => $row['userID'],
				'username' => $row['username'],
				'subject' => $row['subject'],
				'message' => self::fixBBCodes($row['message']),
				'time' => $row['time'],
				'isDeleted' => $row['isDeleted'],
				'isDisabled' => $row['isDisabled'],
				'isClosed' => $row['isClosed'],
				'editorID' => ($row['editorID'] ?: null),
				'editor' => $row['editor'],
				'lastEditTime' => $row['lastEditTime'],
				'editCount' => $row['editCount'],
				'editReason' => $row['editReason'],
				'attachments' => $row['attachments'],
				'enableSmilies' => $row['enableSmilies'],
				'enableHtml' => $row['enableHtml'],
				'enableBBCodes' => $row['enableBBCodes'],
				'showSignature' => $row['showSignature'],
				'ipAddress' => $row['ipAddress'],
				'deleteTime' => $row['deleteTime']
			));
		}
	}
	
	/**
	 * Counts post attachments.
	 */
	public function countPostAttachments() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_attachment
			WHERE	containerType = ?
				AND containerID > ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array('post', 0));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports post attachments.
	 */
	public function exportPostAttachments($offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_attachment
			WHERE		containerType = ?
					AND containerID > ?
			ORDER BY	attachmentID DESC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array('post', 0));
		while ($row = $statement->fetchArray()) {
			$fileLocation = $this->fileSystemPath.'attachments/attachment-'.$row['attachmentID'];
				
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.attachment')->import($row['attachmentID'], array(
				'objectID' => $row['containerID'],
				'userID' => ($row['userID'] ?: null),
				'filename' => $row['attachmentName'],
				'filesize' => $row['attachmentSize'],
				'fileType' => $row['fileType'],
				'isImage' => $row['isImage'],
				'width' => $row['width'],
				'height' => $row['height'],
				'downloads' => $row['downloads'],
				'lastDownloadTime' => $row['lastDownloadTime'],
				'uploadTime' => $row['uploadTime'],
				'showOrder' => $row['showOrder']
			), array('fileLocation' => $fileLocation));
		}
	}
	
	/**
	 * Counts watched threads.
	 */
	public function countWatchedThreads() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wbb".$this->dbNo."_".$this->instanceNo."_thread_subscription";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports watched threads.
	 */
	public function exportWatchedThreads($offset, $limit) {
		$sql = "SELECT		*
			FROM		wbb".$this->dbNo."_".$this->instanceNo."_thread_subscription
			ORDER BY	userID, threadID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.watchedThread')->import(0, array(
				'objectID' => $row['threadID'],
				'userID' => $row['userID']
			));
		}
	}
	
	/**
	 * Counts polls.
	 */
	public function countPolls() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_poll
			WHERE	messageType = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array('post'));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports polls.
	 */
	public function exportPolls($offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_poll
			WHERE		messageType = ?
			ORDER BY	pollID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array('post'));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll')->import($row['pollID'], array(
				'objectID' => $row['messageID'],
				'question' => $row['question'],
				'time' => $row['time'],
				'endTime' => $row['endTime'],
				'isChangeable' => ($row['votesNotChangeable'] ? 0 : 1),
				'isPublic' => $row['isPublic'],
				'sortByVotes' => $row['sortByResult'],
				'maxVotes' => $row['choiceCount'],
				'votes' => $row['votes']
			));
		}
	}
	
	/**
	 * Counts poll options.
	 */
	public function countPollOptions() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_poll_option
			WHERE	pollID IN (
					SELECT	pollID
					FROM	wcf".$this->dbNo."_poll
					WHERE 	messageType = ?	
				)";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array('post'));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports poll options.
	 */
	public function exportPollOptions($offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_poll_option
			WHERE		pollID IN (
						SELECT	pollID
						FROM	wcf".$this->dbNo."_poll
						WHERE 	messageType = ?	
					)
			ORDER BY	pollOptionID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array('post'));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll.option')->import($row['pollOptionID'], array(
				'pollID' => $row['pollID'],
				'optionValue' => $row['pollOption'],
				'showOrder' => $row['showOrder'],
				'votes' => $row['votes']
			));
		}
	}
	
	/**
	 * Counts poll option votes.
	 */
	public function countPollOptionVotes() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_poll_option_vote
			WHERE	pollID IN (
					SELECT	pollID
					FROM	wcf".$this->dbNo."_poll
					WHERE 	messageType = ?
				)
				AND userID <> ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array('post', 0));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports poll option votes.
	 */
	public function exportPollOptionVotes($offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_poll_option_vote
			WHERE		pollID IN (
						SELECT	pollID
						FROM	wcf".$this->dbNo."_poll
						WHERE 	messageType = ?
					)
					AND userID <> ?
			ORDER BY	pollOptionID, userID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array('post', 0));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll.option.vote')->import(0, array(
				'pollID' => $row['pollID'],
				'optionID' => $row['pollOptionID'],
				'userID' => $row['userID']
			));
		}
	}
	
	/**
	 * Counts likes.
	 */
	public function countLikes() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wbb".$this->dbNo."_".$this->instanceNo."_thread_rating
			WHERE	userID <> ?
				AND rating NOT IN (?, ?)";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(0, 0, 3));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports likes.
	 */
	public function exportLikes($offset, $limit) {
		$sql = "SELECT		thread_rating.*, thread.firstPostID, thread.userID AS objectUserID
			FROM		wbb".$this->dbNo."_".$this->instanceNo."_thread_rating thread_rating
			LEFT JOIN	wbb".$this->dbNo."_".$this->instanceNo."_thread thread
			ON		(thread.threadID = thread_rating.threadID)
			WHERE		thread_rating.userID <> ?
					AND thread_rating.rating NOT IN (?, ?)
			ORDER BY	thread_rating.threadID, thread_rating.userID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(0, 0, 3));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.like')->import(0, array(
				'objectID' => $row['firstPostID'],
				'objectUserID' => ($row['objectUserID'] ?: null),
				'userID' => $row['userID'],
				'likeValue' => ($row['rating'] > 3 ? Like::LIKE : Like::DISLIKE)
			));
		}
	}
	
	/**
	 * Counts labels.
	 */
	public function countLabels() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wbb".$this->dbNo."_".$this->instanceNo."_board
			WHERE	prefixMode > ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(0));
		$row = $statement->fetchArray();
		return ($row['count'] ? 1 : 0);
	}
	
	/**
	 * Exports likes.
	 */
	public function exportLabels($offset, $limit) {
		$prefixMap = array();
		
		// get global prefixes
		$globalPrefixes = '';
		$sql = "SELECT	optionValue
			FROM	wcf".$this->dbNo."_option
			WHERE	optionName = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array('thread_default_prefixes'));
		$row = $statement->fetchArray();
		if ($row !== false) $globalPrefixes = $row['optionValue'];
		
		// get boards
		$sql = "SELECT		boardID, prefixes, prefixMode
			FROM		wbb".$this->dbNo."_".$this->instanceNo."_board
			WHERE		prefixMode > ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(0));
		while ($row = $statement->fetchArray()) {
			$prefixes = '';
			
			switch ($row['prefixMode']) {
				case 1:
					$prefixes = $globalPrefixes;
					break;
				case 2:
					$prefixes = $row['prefixes'];
					break;
				case 3:
					$prefixes = $globalPrefixes . "\n" . $row['prefixes'];
					break;
			}
			
			$prefixes = StringUtil::trim(StringUtil::unifyNewlines($prefixes));
			if ($prefixes) {
				$key = StringUtil::getHash($prefixes);
				if (!isset($prefixMap[$key])) {
					$prefixMap[$key] = array(
						'prefixes' => $prefixes,
						'boardIDs' => array()
					);
				}
				
				$boardID = ImportHandler::getInstance()->getNewID('com.woltlab.wbb.board', $row['boardID']);
				if ($boardID) $prefixMap[$key]['boardIDs'][] = $boardID;
			}
		}
		
		// save prefixes
		if (!empty($prefixMap)) {
			$i = 1;
			$objectType = ObjectTypeCache::getInstance()->getObjectTypeByName('com.woltlab.wcf.label.objectType', 'com.woltlab.wbb.board');
			
			foreach ($prefixMap as $key => $data) {
				// import label group
				ImportHandler::getInstance()->getImporter('com.woltlab.wcf.label.group')->import($key, array(
					'groupName' => 'labelgroup'.$i
				), array('objects' => array($objectType->objectTypeID => $data['boardIDs'])));
				
				// import labels
				$labels = explode("\n", $data['prefixes']);
				foreach ($labels as $label) {
					ImportHandler::getInstance()->getImporter('com.woltlab.wcf.label')->import($key.'-'.$label, array(
						'groupID' => $key,
						'label' => $label
					));
				}
				
				$i++;
			}
		}
	}
	
	/**
	 * Counts ACLs.
	 */
	public function countACLs() {
		$sql = "SELECT	(SELECT COUNT(*) FROM wbb".$this->dbNo."_".$this->instanceNo."_board_moderator)
				+ (SELECT COUNT(*) FROM wbb".$this->dbNo."_".$this->instanceNo."_board_to_group)
				+ (SELECT COUNT(*) FROM wbb".$this->dbNo."_".$this->instanceNo."_board_to_user) AS count";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(0));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports ACLs.
	 */
	public function exportACLs($offset, $limit) {
		// get ids
		$mod = $user = $group = array();
		$sql = "(
				SELECT	boardID, userID, groupID, 'mod' AS type
				FROM	wbb".$this->dbNo."_".$this->instanceNo."_board_moderator
			)
			UNION
			(
				SELECT	boardID, 0 AS userID, groupID, 'group' AS type
				FROM	wbb".$this->dbNo."_".$this->instanceNo."_board_to_group
			)
			UNION			
			(
				SELECT	boardID, userID, 0 AS groupID, 'user' AS type
				FROM	wbb".$this->dbNo."_".$this->instanceNo."_board_to_user
			)			
			ORDER BY	boardID, userID, groupID, type";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			${$row['type']}[] = $row; 
		}
		
		// mods
		if (!empty($mod)) {
			$conditionBuilder = new PreparedStatementConditionBuilder(true, 'OR');
			foreach ($mod as $row) {
				$conditionBuilder->add('(boardID = ? AND userID = ? AND groupID = ?)', array($row['boardID'], $row['userID'], $row['groupID']));
			}
			
			$sql = "SELECT	*
				FROM	wbb".$this->dbNo."_".$this->instanceNo."_board_moderator
				".$conditionBuilder;
			$statement = $this->database->prepareStatement($sql);
			$statement->execute($conditionBuilder->getParameters());
			while ($row = $statement->fetchArray()) {
				$data = array(
					'objectID' => $row['boardID']
				);
				if ($row['userID']) $data['userID'] = $row['userID'];
				else if ($row['groupID']) $data['groupID'] = $row['groupID'];
				
				unset($row['boardID'], $row['userID'], $row['groupID']);
				
				foreach ($row as $permission => $value) {
					ImportHandler::getInstance()->getImporter('com.woltlab.wbb.acl')->import(0, array_merge($data, array('optionValue' => $value)), array('optionName' => $permission));
				}
			}
		}
		
		// groups
		if (!empty($group)) {
			$conditionBuilder = new PreparedStatementConditionBuilder(true, 'OR');
			foreach ($group as $row) {
				$conditionBuilder->add('(boardID = ? AND groupID = ?)', array($row['boardID'], $row['groupID']));
			}
				
			$sql = "SELECT	*
				FROM	wbb".$this->dbNo."_".$this->instanceNo."_board_to_group
				".$conditionBuilder;
			$statement = $this->database->prepareStatement($sql);
			$statement->execute($conditionBuilder->getParameters());
			while ($row = $statement->fetchArray()) {
				$data = array(
					'objectID' => $row['boardID']
				);
				$data['groupID'] = $row['groupID'];
		
				unset($row['boardID'], $row['groupID']);
		
				foreach ($row as $permission => $value) {
					ImportHandler::getInstance()->getImporter('com.woltlab.wbb.acl')->import(0, array_merge($data, array('optionValue' => $value)), array('optionName' => $permission));
				}
			}
		}
		
		// users
		if (!empty($group)) {
			$conditionBuilder = new PreparedStatementConditionBuilder(true, 'OR');
			foreach ($user as $row) {
				$conditionBuilder->add('(boardID = ? AND userID = ?)', array($row['boardID'], $row['userID']));
			}
		
			$sql = "SELECT	*
				FROM	wbb".$this->dbNo."_".$this->instanceNo."_board_to_user
				".$conditionBuilder;
			$statement = $this->database->prepareStatement($sql);
			$statement->execute($conditionBuilder->getParameters());
			while ($row = $statement->fetchArray()) {
				$data = array(
					'objectID' => $row['boardID']
				);
				$data['userID'] = $row['userID'];
		
				unset($row['boardID'], $row['userID']);
		
				foreach ($row as $permission => $value) {
					ImportHandler::getInstance()->getImporter('com.woltlab.wbb.acl')->import(0, array_merge($data, array('optionValue' => $value)), array('optionName' => $permission));
				}
			}
		}
	}
	
	/**
	 * Counts smilies.
	 */
	public function countSmilies() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_smiley";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports smilies.
	 */
	public function exportSmilies($offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_smiley
			ORDER BY	smileyID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array());
		while ($row = $statement->fetchArray()) {
			$fileLocation = $this->fileSystemPath . $row['smileyPath'];
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.smiley')->import($row['smileyID'], array(
				'smileyTitle' => $row['smileyTitle'],
				'smileyCode' => $row['smileyCode'],
				'showOrder' => $row['showOrder']
			), array('fileLocation' => $fileLocation));
		}
	}
	
	/**
	 * Gets existing WCF2.0 user options.
	 *
	 * @return array
	 */
	private function getExistingUserOptions() {
		$optionsNames = array();
		$sql = "SELECT	optionName
			FROM	wcf".WCF_N."_user_option
			WHERE	optionName NOT LIKE ?";
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute(array('option%'));
		while ($row = $statement->fetchArray()) {
			$optionsNames[] = $row['optionName'];
		}
	
		return $optionsNames;
	}
	
	private function searchPlugin($name) {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_package
			WHERE	package = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array($name));
		$row = $statement->fetchArray();
		if ($row['count']) return true;
		return false;
	}
	
	private static function fixBBCodes($message) {
		// code bbcodes
		$message = preg_replace('~\[(php|java|css|html|xml|tpl|js|c)\]~', '[code=\\1]', $message);
		$message = preg_replace('~\[(php|java|css|html|xml|tpl|js|c)=(\d+)\]~', '[code=\\1,\\2]', $message);
		$message = str_replace('[mysql]', '[code=sql]', $message);
		$message = preg_replace('~\[mysql=(\d+)\]~', '[code=sql,\\1]', $message);
		$message = preg_replace('~\[/(?:php|java|css|html|xml|tpl|js|c|mysql)\]~', '[/code]', $message);
		
		// media bbcodes
		$message = preg_replace("~\[(?:youtube|myvideo|myspace|googlevideo|clipfish|sevenload)(?:='?([^'\],]+)'?)?(?:,[^\]]+)?\]~", '[media]\\1', $message);
		$message = preg_replace('~\[/(?:youtube|myvideo|myspace|googlevideo|clipfish|sevenload)\]~', '[/media]', $message);
		
		return $message;
	}
}
