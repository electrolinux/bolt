<?php

namespace Bolt\EventListener;

use Bolt\AccessControl\PasswordHashManager;
use Bolt\AccessControl\Permissions;
use Bolt\Events\HydrationEvent;
use Bolt\Events\StorageEvent;
use Bolt\Events\StorageEvents;
use Bolt\Logger\FlashLoggerInterface;
use Bolt\Request\ProfilerAwareTrait;
use Bolt\Storage\Database\Schema;
use Bolt\Storage\Entity;
use Bolt\Storage\EventProcessor;
use Bolt\Translation\Translator as Trans;
use Silex\Application;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class StorageEventListener implements EventSubscriberInterface
{
    use ProfilerAwareTrait;

    /** @var EventProcessor\TimedRecord */
    protected $timedRecord;
    /** @var Schema\SchemaManagerInterface */
    protected $schemaManager;
    /** @var UrlGeneratorInterface */
    protected $urlGenerator;
    /** @var \Bolt\Logger\FlashLoggerInterface */
    protected $loggerFlash;
    /** @var PasswordHashManager */
    protected $passwordHash;
    /** @var integer */
    protected $hashStrength;
    /** @var bool */
    protected $timedRecordsEnabled;

    /**
     * Constructor.
     *
     * @param EventProcessor\TimedRecord    $timedRecord
     * @param Schema\SchemaManagerInterface $schemaManager
     * @param UrlGeneratorInterface         $urlGenerator
     * @param FlashLoggerInterface          $loggerFlash
     * @param PasswordHashManager           $passwordHash
     * @param integer                       $hashStrength
     * @param bool                          $timedRecordsEnabled
     */
    public function __construct(
        EventProcessor\TimedRecord $timedRecord,
        Schema\SchemaManagerInterface $schemaManager,
        UrlGeneratorInterface $urlGenerator,
        FlashLoggerInterface $loggerFlash,
        PasswordHashManager $passwordHash,
        $hashStrength,
        $timedRecordsEnabled
    ) {
        $this->timedRecord = $timedRecord;
        $this->schemaManager = $schemaManager;
        $this->urlGenerator = $urlGenerator;
        $this->loggerFlash = $loggerFlash;
        $this->passwordHash = $passwordHash;
        $this->hashStrength = $hashStrength;
        $this->timedRecordsEnabled = $timedRecordsEnabled;
    }

    /**
     * Pre-save storage event for user entities.
     *
     * @param StorageEvent $event
     */
    public function onUserEntityPreSave(StorageEvent $event)
    {
        /** @var Entity\Users $entityRecord */
        $entityRecord = $event->getContent();

        if ($entityRecord instanceof Entity\Users) {
            $this->passwordHash($entityRecord);
        }
    }

    /**
     * Post hydration storage event.
     *
     * @param HydrationEvent $event
     */
    public function onPostHydrate(HydrationEvent $event)
    {
        $entity = $event->getSubject();
        if (!$entity instanceof Entity\Users) {
            return;
        }

        // Ensure Permissions::ROLE_EVERYONE always exists
        $roles = $entity->getRoles();
        if (!in_array(Permissions::ROLE_EVERYONE, $roles)) {
            $roles[] = Permissions::ROLE_EVERYONE;
            $entity->setRoles($roles);
        }
    }

    /**
     * Kernel request listener callback.
     *
     * @param GetResponseEvent $event
     */
    public function onKernelRequest(GetResponseEvent $event)
    {
        if (!$event->isMasterRequest()) {
            return;
        }
        if ($this->isProfilerRequest($event->getRequest())) {
            return;
        }

        $this->schemaCheck($event);

        // Check if we need to 'publish' any 'timed' records, or 'hold' any expired records.
        if ($this->timedRecordsEnabled && $this->timedRecord->isDuePublish()) {
            $this->timedRecord->publishTimedRecords();
        }
        if ($this->timedRecordsEnabled && $this->timedRecord->isDueHold()) {
            $this->timedRecord->holdExpiredRecords();
        }
    }

    /**
     * Trigger database schema checks if required.
     *
     * @param GetResponseEvent $event
     */
    protected function schemaCheck(GetResponseEvent $event)
    {
        $session = $event->getRequest()->getSession();
        $validSession = $session->isStarted() && $session->get('authentication');
        $expired = $this->schemaManager->isCheckRequired();

        // Don't show the check if we're in the dbcheck already.
        $notInCheck = !in_array(
            $event->getRequest()->get('_route'),
            ['dbupdate', '_wdt']
        );

        if ($validSession && $expired && $this->schemaManager->isUpdateRequired() && $notInCheck) {
            $msg = Trans::__(
                "The database needs to be updated/repaired. Go to 'Configuration' > '<a href=\"%link%\">Check Database</a>' to do this now.",
                ['%link%' => $this->urlGenerator->generate('dbcheck')]
                );
            $this->loggerFlash->error($msg);
        }
    }

    /**
     * Hash user passwords on save.
     *
     * @param Entity\Users $usersEntity
     */
    protected function passwordHash(Entity\Users $usersEntity)
    {
        $password = $usersEntity->getPassword();
        if ($password !== null) {
            $hash = $this->passwordHash->createHash($password);
            $usersEntity->setPassword($hash);
        }
    }

    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::REQUEST       => ['onKernelRequest', 31],
            StorageEvents::PRE_SAVE     => ['onUserEntityPreSave', Application::EARLY_EVENT],
            StorageEvents::POST_HYDRATE => 'onPostHydrate',
        ];
    }
}
