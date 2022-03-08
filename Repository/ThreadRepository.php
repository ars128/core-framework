<?php

namespace Webkul\UVDesk\CoreFrameworkBundle\Repository;

use Doctrine\ORM\Query;
use Doctrine\Common\Collections\Criteria;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\User;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\Ticket;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\Attachment;

/**
 * ThreadRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class ThreadRepository extends \Doctrine\ORM\EntityRepository
{
    const DEFAULT_PAGINATION_LIMIT = 15;

    public function findTicketBySubject($email, $subject)
    {
        if (stripos($subject,"RE: ") !== false) {
            $subject = str_ireplace("RE: ", "", $subject);
        }

        if (stripos($subject,"FWD: ") !== false) {
            $subject = str_ireplace("FWD: ","",$subject);
        }

        $ticket = $this->getEntityManager()->createQuery("SELECT t FROM UVDeskCoreFrameworkBundle:Ticket t WHERE t.subject LIKE :referenceIds" )
            ->setParameter('referenceIds', '%' . $subject . '%')
            ->setMaxResults(1)
            ->getOneOrNullResult();

        return ($ticket && strtolower($ticket->getCustomer()->getEmail()) == strtolower($email)) ? $ticket : null;
    }

    public function getTicketCurrentThread($ticket)
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select("thread")
            ->from('UVDeskCoreFrameworkBundle:Thread', 'thread')
            ->where('thread.ticket = :ticket')->setParameter('ticket', $ticket)
            ->orderBy('thread.id', Criteria::DESC)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function prepareBasePaginationRecentThreadsQuery($ticket, array $params, $enabledLockedThreads = true)
    {
        $queryBuilder = $this->getEntityManager()->createQueryBuilder()
            ->select("thread, attachments, user, userInstance")
            ->from('UVDeskCoreFrameworkBundle:Thread', 'thread')
            ->leftJoin('thread.user', 'user')
            ->leftJoin('user.userInstance', 'userInstance')
            ->leftJoin('thread.attachments', 'attachments')
            ->where('thread.ticket = :ticket')->setParameter('ticket', $ticket)
            ->andWhere('thread.threadType != :disabledThreadType')->setParameter('disabledThreadType', 'create')
            ->orderBy('thread.id', Criteria::DESC);

        // Filter locked threads
        if (false === $enabledLockedThreads) {
            $queryBuilder->andWhere('thread.isLocked = :isThreadLocked')->setParameter('isThreadLocked', false);
        }

        // Filter threads by their type
        switch (!empty($params['threadType']) ? $params['threadType'] : 'reply') {
            case 'reply':
                $queryBuilder->andWhere('thread.threadType = :threadType')->setParameter('threadType', 'reply');
                break;
            case 'forward':
                $queryBuilder->andWhere('thread.threadType = :threadType')->setParameter('threadType', 'forward');
                break;
            case 'note':
                $queryBuilder->andWhere('thread.threadType = :threadType')->setParameter('threadType', 'note');
                break;
            case 'bookmark':
            case 'pinned':
                $queryBuilder->andWhere('thread.isBookmarked = :isBookmarked')->setParameter('isBookmarked', true);
                break;
            case 'task':
                // $queryBuilder->andWhere('thread.threadType = :threadType')->setParameter('threadType', 'forward');
                break;
            default:
                break;
        }

        return $queryBuilder;
    }

    public function getAllCustomerThreads($ticketId,\Symfony\Component\HttpFoundation\ParameterBag $obj = null, $container)
    {
        $json = array();
        $entityManager = $this->getEntityManager();
        $qb = $entityManager->createQueryBuilder()
            ->select("th, a, u.id as userId, CONCAT(u.firstName, ' ', u.lastName) as fullname, userInstance.profileImagePath as smallThumbnail")->from($this->getEntityName(), 'th')
            ->leftJoin('th.user', 'u')
            ->leftJoin('th.attachments', 'a')
            ->leftJoin('u.userInstance', 'userInstance')
            ->andwhere('th.threadType = :threadType')
            ->setParameter('threadType', 'reply')
            ->andwhere('th.ticket = :ticketId')
            ->setParameter('ticketId', $ticketId)
            ->orderBy('th.id', 'DESC');

        $data = $obj->all();

        $newQb = clone $qb;
        $newQb->select('COUNT(DISTINCT th.id)');
        $paginator = $container->get('knp_paginator');
        $results = $paginator->paginate(
            $qb->getQuery()->setHydrationMode(Query::HYDRATE_ARRAY)->setHint('knp_paginator.count', $newQb->getQuery()->getSingleScalarResult()),
            isset($data['page']) ? $data['page'] : 1,
            self::DEFAULT_PAGINATION_LIMIT,
            array('distinct' => true)
        );

        $paginationData = $results->getPaginationData();
        $queryParameters = $results->getParams();

        $queryParameters['page'] = "replacePage";
        $paginationData['url'] = '#'.$container->get('uvdesk.service')->buildPaginationQuery($queryParameters);

        $data = array();
        $userService = $container->get('user.service');
        $uvdeskFileSystemService = $container->get('uvdesk.core.file_system.service');

        foreach ($results->getItems() as $key => $row) {
            $thread = $row[0];
            $threadResponse = [
                'id' => $thread['id'],
                'user' => $row['userId'] ? ['id' => $row['userId']] : null,
                'fullname' => $row['fullname'],
                'smallThumbnail'=> $row['smallThumbnail'],
                'reply' => html_entity_decode($thread['message']),
                'source' => $thread['source'],
                'threadType' => $thread['threadType'],
                'userType' => $thread['createdBy'],
                'formatedCreatedAt' => $userService->getLocalizedFormattedTime($thread['createdAt'], $userService->getSessionUser()),
                'timestamp' => $userService->convertToDatetimeTimezoneTimestamp($thread['createdAt']),
                'cc' => $thread['cc'],
                'bcc' => $thread['bcc'],
                'attachments' => $thread['attachments'],
            ];

            if (!empty($threadResponse['attachments'])) {
                $threadResponse['attachments'] = array_map(function ($attachment) use ($entityManager, $uvdeskFileSystemService) {
                    $attachmentReferenceObject = $entityManager->getReference(Attachment::class, $attachment['id']);
                    return $uvdeskFileSystemService->getFileTypeAssociations($attachmentReferenceObject);
                }, $threadResponse['attachments']);
            }

            array_push($data, $threadResponse);
        }
        
        $json['threads'] = $data;
        $json['pagination'] = $paginationData;

        return $json;
    }

    public function findThreadByRefrenceId($referenceIds)
    {
        $query = $this->getEntityManager()
            ->createQuery(
                "SELECT t FROM UVDeskCoreFrameworkBundle:Ticket t
                WHERE t.referenceIds LIKE :referenceIds
                ORDER BY t.id DESC"
            )->setParameter('referenceIds', '%'.$referenceIds.'%');

        return $query->setMaxResults(1)->getOneOrNullResult();
    }

}
