<?php

declare(strict_types=1);

namespace OCA\PaperlessForwarder\Service;

use OCP\Files\File;
use OCP\IDBConnection;

class StateStore {
	public function __construct(private IDBConnection $db) {
	}

	public function queue(File $file, string $ownerId, string $path): void {
		$id = $file->getId();
		$etag = $file->getEtag();
		$size = $file->getSize();
		$builder = $this->db->getQueryBuilder();
		$builder->select('etag', 'size')
			->from('paperlessfwd_files')
			->where($builder->expr()->eq('file_id', $builder->createNamedParameter($id)));
		$existing = $builder->executeQuery()->fetchAssociative();

		if ($existing === false) {
			$builder = $this->db->getQueryBuilder();
			$builder->insert('paperlessfwd_files')
				->setValue('file_id', $builder->createNamedParameter($id))
				->setValue('owner_id', $builder->createNamedParameter($ownerId))
				->setValue('path', $builder->createNamedParameter($path))
				->setValue('etag', $builder->createNamedParameter($etag))
				->setValue('size', $builder->createNamedParameter($size))
				->setValue('state', $builder->createNamedParameter('queued'))
				->setValue('rounds', $builder->createNamedParameter(0))
				->setValue('attempts', $builder->createNamedParameter(0))
				->setValue('poll_attempts', $builder->createNamedParameter(0))
				->setValue('rejections', $builder->createNamedParameter(0))
				->setValue('updated_at', $builder->createNamedParameter(time()));
			$builder->executeStatement();
			return;
		}

		$changed = $existing['etag'] !== $etag || (int)$existing['size'] !== $size;
		$builder = $this->db->getQueryBuilder();
		$builder->update('paperlessfwd_files')
			->set('owner_id', $builder->createNamedParameter($ownerId))
			->set('path', $builder->createNamedParameter($path))
			->set('updated_at', $builder->createNamedParameter(time()))
			->where($builder->expr()->eq('file_id', $builder->createNamedParameter($id)));
		if ($changed) {
			$builder->set('etag', $builder->createNamedParameter($etag))
				->set('size', $builder->createNamedParameter($size))
				->set('state', $builder->createNamedParameter('queued'))
				->set('rounds', $builder->createNamedParameter(0))
				->set('attempts', $builder->createNamedParameter(0))
				->set('poll_attempts', $builder->createNamedParameter(0))
				->set('rejections', $builder->createNamedParameter(0))
				->set('task_id', $builder->createNamedParameter(null))
				->set('next_attempt_at', $builder->createNamedParameter(0))
				->set('reason', $builder->createNamedParameter(null))
				->set('last_error', $builder->createNamedParameter(null));
		}
		$builder->executeStatement();
	}

	/** @return list<array<string, mixed>> */
	public function due(int $limit = 10): array {
		$builder = $this->db->getQueryBuilder();
		$builder->select('*')
			->from('paperlessfwd_files')
			->where($builder->expr()->in('state', $builder->createNamedParameter(['queued', 'waiting'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($builder->expr()->lte('next_attempt_at', $builder->createNamedParameter(time())))
			->setMaxResults($limit);
		return $builder->executeQuery()->fetchAllAssociative();
	}

	/** @param array<string, int|string|null> $values */
	public function update(int $fileId, array $values): void {
		$builder = $this->db->getQueryBuilder();
		$builder->update('paperlessfwd_files');
		foreach ($values as $column => $value) {
			$builder->set($column, $builder->createNamedParameter($value));
		}
		$builder->set('updated_at', $builder->createNamedParameter(time()))
			->where($builder->expr()->eq('file_id', $builder->createNamedParameter($fileId)));
		$builder->executeStatement();
	}

	public function retryParked(): int {
		$builder = $this->db->getQueryBuilder();
		$builder->update('paperlessfwd_files')
			->set('state', $builder->createNamedParameter('queued'))
			->set('next_attempt_at', $builder->createNamedParameter(time()))
			->set('reason', $builder->createNamedParameter(null))
			->set('last_error', $builder->createNamedParameter(null))
			->where($builder->expr()->eq('state', $builder->createNamedParameter('parked')));
		return $builder->executeStatement();
	}

	/** @return list<array<string, mixed>> */
	public function all(): array {
		$builder = $this->db->getQueryBuilder();
		$builder->select('*')->from('paperlessfwd_files')->orderBy('updated_at', 'DESC')->setMaxResults(200);
		return $builder->executeQuery()->fetchAllAssociative();
	}
}
