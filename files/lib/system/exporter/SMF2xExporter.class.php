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
			/*	'com.woltlab.wcf.user.avatar',
				'com.woltlab.wcf.user.option',
				'com.woltlab.wcf.user.follower',
				'com.woltlab.wcf.user.rank'*/
			),
			/*'com.woltlab.wbb.board' => array(
				'com.woltlab.wbb.acl',
				'com.woltlab.wbb.attachment',
				'com.woltlab.wbb.poll',
				'com.woltlab.wbb.watchedThread',
				'com.woltlab.wbb.like',
				'com.woltlab.wcf.label'
			),
			'com.woltlab.wcf.conversation' => array(
				'com.woltlab.wcf.conversation.label'
			),
			'com.woltlab.wcf.smiley' => array()*/
		);
	}
	
	/**
	 * @see wcf\system\exporter\IExporter::validateDatabaseAccess()
	 */
	public function validateDatabaseAccess() {
		parent::validateDatabaseAccess();
		
		$sql = "SELECT	value
			FROM	".$this->databasePrefix."settings
			WHERE	variable = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array('smfVersion'));
		$row = $statement->fetchArray();
		
		if (version_compare($row['value'], '2.0.0', '<=')) throw new DatabaseException('Cannot import less than SMF 2.x', $this->database);
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
			/*	if (in_array('com.woltlab.wcf.user.rank', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.rank';*/
			}
			
			/*if (in_array('com.woltlab.wcf.user.option', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.option';*/
			$queue[] = 'com.woltlab.wcf.user';
			/*if (in_array('com.woltlab.wcf.user.avatar', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.avatar';
			
			if (in_array('com.woltlab.wcf.user.follower', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.follower';
			
			// conversation
			if (in_array('com.woltlab.wcf.conversation', $this->selectedData)) {
				if (in_array('com.woltlab.wcf.conversation.label', $this->selectedData)) $queue[] = 'com.woltlab.wcf.conversation.label';
				
				$queue[] = 'com.woltlab.wcf.conversation';
				$queue[] = 'com.woltlab.wcf.conversation.user';
			}*/
		}
		/*
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
		*/
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
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."members
			ORDER BY	id_member ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		
		while ($row = $statement->fetchArray()) {
			$data = array(
				'username' => $row['member_name'],
				'password' => '',
				'email' => $row['email_address'],
				'registrationDate' => $row['date_registered'],
				'banned' => 0, // TODO: banned
				'banReason' => '',
				'activationCode' => $row['validation_code'] ? UserRegistrationUtil::getActivationCode() : 0, // smf's codes are strings
				'registrationIpAddress' => '', // TODO: Find out whether and where this is saved
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
}
