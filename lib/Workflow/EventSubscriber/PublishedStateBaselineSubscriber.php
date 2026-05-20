<?php
declare(strict_types=1);

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Workflow\EventSubscriber;

use Doctrine\DBAL\Connection;
use Pimcore\Event\AssetEvents;
use Pimcore\Event\DataObjectEvents;
use Pimcore\Event\DocumentEvents;
use Pimcore\Event\Model\ElementEventInterface;
use Pimcore\Event\Model\VersionEvent;
use Pimcore\Event\VersionEvents;
use Pimcore\Model\Element\ElementInterface;
use Pimcore\Model\Element\Service;
use Pimcore\Workflow\Manager;
use Pimcore\Workflow\MarkingStore\StateTableMarkingStore;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Keeps a "last published" snapshot of the marking for workflows that use the
 * versioning variant of the state_table marking store, so that discarding an
 * autosave draft reverts the marking back to the last published value.
 *
 * @internal
 */
class PublishedStateBaselineSubscriber implements EventSubscriberInterface
{
    private const TABLE = 'element_workflow_state';

    public function __construct(
        private Manager $workflowManager,
        private Connection $db,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            DataObjectEvents::POST_UPDATE => 'onElementPostUpdate',
            AssetEvents::POST_UPDATE => 'onElementPostUpdate',
            DocumentEvents::POST_UPDATE => 'onElementPostUpdate',
            VersionEvents::POST_DELETE => 'onVersionPostDelete',
        ];
    }

    public function onElementPostUpdate(ElementEventInterface $event): void
    {
        // saveVersionOnly is set only when called from saveVersion() (i.e. a
        // draft / autosave). A real publish goes through the main update path
        // and does not set this argument.
        if ($event->hasArgument('saveVersionOnly') && $event->getArgument('saveVersionOnly')) {
            return;
        }

        $element = $event->getElement();
        foreach ($this->getVersioningWorkflows($element) as $workflowName) {
            $this->captureBaseline($element, $workflowName);
        }
    }

    public function onVersionPostDelete(VersionEvent $event): void
    {
        $version = $event->getVersion();
        if (!$version->isAutoSave()) {
            return;
        }

        $element = Service::getElementById($version->getCtype(), $version->getCid());
        if (!$element instanceof ElementInterface) {
            return;
        }

        foreach ($this->getVersioningWorkflows($element) as $workflowName) {
            $this->restoreFromBaseline($element, $workflowName);
        }
    }

    /**
     * @return string[] workflow names whose marking store is a versioning state_table
     */
    private function getVersioningWorkflows(ElementInterface $element): array
    {
        $workflows = [];
        foreach ($this->workflowManager->getAllWorkflowsForSubject($element) as $workflow) {
            if ($this->isVersioningStateTable($workflow)) {
                $workflows[] = $workflow->getName();
            }
        }

        return $workflows;
    }

    private function isVersioningStateTable(WorkflowInterface $workflow): bool
    {
        $markingStore = $workflow->getMarkingStore();

        return $markingStore instanceof StateTableMarkingStore && $markingStore->isVersioning();
    }

    private function captureBaseline(ElementInterface $element, string $workflowName): void
    {
        $this->db->executeStatement(
            'UPDATE ' . self::TABLE . ' SET publishedPlace = place WHERE cid = ? AND ctype = ? AND workflow = ?',
            [$element->getId(), Service::getElementType($element), $workflowName]
        );
    }

    private function restoreFromBaseline(ElementInterface $element, string $workflowName): void
    {
        $cid = $element->getId();
        $ctype = Service::getElementType($element);

        $publishedPlace = $this->db->fetchOne(
            'SELECT publishedPlace FROM ' . self::TABLE . ' WHERE cid = ? AND ctype = ? AND workflow = ?',
            [$cid, $ctype, $workflowName]
        );

        if ($publishedPlace === false) {
            // No row exists for this element/workflow, nothing to restore.
            return;
        }

        if ($publishedPlace === null || $publishedPlace === '') {
            // No baseline has been captured yet (the element was never published
            // with a marking). Remove the in-flight marking so the element
            // leaves the workflow, matching the object marking store behaviour.
            $this->db->delete(self::TABLE, [
                'cid' => $cid,
                'ctype' => $ctype,
                'workflow' => $workflowName,
            ]);

            return;
        }

        $this->db->executeStatement(
            'UPDATE ' . self::TABLE . ' SET place = publishedPlace WHERE cid = ? AND ctype = ? AND workflow = ?',
            [$cid, $ctype, $workflowName]
        );
    }
}
