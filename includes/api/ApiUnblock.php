<?php
/**
 * Copyright © 2007 Roan Kattouw "<Firstname>.<Lastname>@gmail.com"
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

use MediaWiki\Block\BlockPermissionCheckerFactory;
use MediaWiki\Block\DatabaseBlock;
use MediaWiki\Block\UnblockUserFactory;
use MediaWiki\ParamValidator\TypeDef\UserDef;

/**
 * API module that facilitates the unblocking of users. Requires API write mode
 * to be enabled.
 *
 * @ingroup API
 */
class ApiUnblock extends ApiBase {

	use ApiBlockInfoTrait;

	/** @var BlockPermissionCheckerFactory */
	private $permissionCheckerFactory;

	/** @var UnblockUserFactory */
	private $unblockUserFactory;

	/** @var UserCache */
	private $userCache;

	public function __construct(
		ApiMain $main,
		$action,
		BlockPermissionCheckerFactory $permissionCheckerFactory,
		UnblockUserFactory $unblockUserFactory,
		UserCache $userCache
	) {
		parent::__construct( $main, $action );

		$this->permissionCheckerFactory = $permissionCheckerFactory;
		$this->unblockUserFactory = $unblockUserFactory;
		$this->userCache = $userCache;
	}

	/**
	 * Unblocks the specified user or provides the reason the unblock failed.
	 */
	public function execute() {
		$performer = $this->getUser();
		$params = $this->extractRequestParams();

		$this->requireOnlyOneParameter( $params, 'id', 'user', 'userid' );

		if ( !$this->getAuthority()->isAllowed( 'block' ) ) {
			$this->dieWithError( 'apierror-permissiondenied-unblock', 'permissiondenied' );
		}

		if ( $params['userid'] !== null ) {
			$username = $this->userCache->getProp( $params['userid'], 'name' );

			if ( $username === false ) {
				$this->dieWithError( [ 'apierror-nosuchuserid', $params['userid'] ], 'nosuchuserid' );
			} else {
				$params['user'] = $username;
			}
		}

		$target = $params['id'] === null ? $params['user'] : "#{$params['id']}";

		# T17810: blocked admins should have limited access here
		$status = $this->permissionCheckerFactory
			->newBlockPermissionChecker(
				$target,
				$this->getAuthority()
			)->checkBlockPermissions();
		if ( $status !== true ) {
			$this->dieWithError(
				$status,
				null,
				[ 'blockinfo' => $this->getBlockDetails( $performer->getBlock() ) ]
			);
		}

		$status = $this->unblockUserFactory->newUnblockUser(
			$target,
			$this->getAuthority(),
			$params['reason'],
			$params['tags'] ?? []
		)->unblock();

		if ( !$status->isOK() ) {
			$this->dieStatus( $status );
		}

		$block = $status->getValue();
		$targetName = $block->getType() === DatabaseBlock::TYPE_AUTO ? '' : $block->getTargetName();
		$targetUserId = $block->getTargetUserIdentity() ? $block->getTargetUserIdentity()->getId() : 0;
		$res = [
			'id' => $block->getId(),
			'user' => $targetName,
			'userid' => $targetUserId,
			'reason' => $params['reason']
		];
		$this->getResult()->addValue( null, $this->getModuleName(), $res );
	}

	public function mustBePosted() {
		return true;
	}

	public function isWriteMode() {
		return true;
	}

	public function getAllowedParams() {
		return [
			'id' => [
				ApiBase::PARAM_TYPE => 'integer',
			],
			'user' => [
				ApiBase::PARAM_TYPE => 'user',
				UserDef::PARAM_ALLOWED_USER_TYPES => [ 'name', 'ip', 'cidr', 'id' ],
			],
			'userid' => [
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_DEPRECATED => true,
			],
			'reason' => '',
			'tags' => [
				ApiBase::PARAM_TYPE => 'tags',
				ApiBase::PARAM_ISMULTI => true,
			],
		];
	}

	public function needsToken() {
		return 'csrf';
	}

	protected function getExamplesMessages() {
		return [
			'action=unblock&id=105'
				=> 'apihelp-unblock-example-id',
			'action=unblock&user=Bob&reason=Sorry%20Bob'
				=> 'apihelp-unblock-example-user',
		];
	}

	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/Special:MyLanguage/API:Block';
	}
}
