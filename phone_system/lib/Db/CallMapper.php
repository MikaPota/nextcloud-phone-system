<?php

declare(strict_types=1);

namespace OCA\PhoneSystem\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Call> */
class CallMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'phone_system_calls', Call::class);
	}

	/** @return Call[] newest first; a user sees only their own calls */
	public function recent(string $uid, int $limit = 50): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
			->orderBy('started_at', 'DESC')->addOrderBy('id', 'DESC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}
}
