<?php
namespace Oro\Bundle\ImportExportBundle\Async\Import;

use Doctrine\Common\Persistence\ManagerRegistry;

use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\ImportExportBundle\Async\ImportExportResultSummarizer;
use Oro\Bundle\ImportExportBundle\Async\Topics;
use Oro\Bundle\ImportExportBundle\File\FileManager;
use Oro\Bundle\NotificationBundle\Async\Topics as NotifcationTopics;
use Oro\Bundle\UserBundle\Entity\User;
use Oro\Component\MessageQueue\Job\Job;
use Oro\Component\MessageQueue\Job\JobRunner;
use Oro\Component\MessageQueue\Util\JSON;

class PreHttpImportMessageProcessor extends PreImportMessageProcessorAbstract
{
    /**
     * @var ManagerRegistry
     */
    protected $managerRegistry;

    /**
     * @param ManagerRegistry $managerRegistry
     */
    public function setManagerRegistry(ManagerRegistry $managerRegistry)
    {
        $this->managerRegistry = $managerRegistry;
    }

    /**
     * {@inheritdoc}
     */
    protected function validateMessageBody($body)
    {
        if (! isset(
            $body['userId'],
            $body['securityToken'],
            $body['jobName'],
            $body['process'],
            $body['fileName'],
            $body['originFileName']
        )) {
            $this->logger->critical(
                sprintf('[PreHttpImportMessageProcessor] Got invalid message. body: %s', JSON::encode($body)),
                ['body' => $body]
            );

            return false;
        }

        $body = array_replace_recursive([
            'processorAlias' => null,
            'options' => [],
        ], $body);

        $body['options']['batch_size'] = $this->batchSize;

        return $body;
    }

    /**
     * {@inheritdoc}
     */
    protected function processJob($parentMessageId, $body, $files)
    {
        $jobName = sprintf(
            'oro:%s:%s:%s:%s',
            $body['process'],
            $body['processorAlias'],
            $body['jobName'],
            $body['userId']
        );

        $result = $this->jobRunner->runUnique(
            $parentMessageId,
            $jobName,
            function (JobRunner $jobRunner, Job $job) use ($jobName, $body, $files) {
                foreach ($files as $key => $file) {
                    $jobRunner->createDelayed(
                        sprintf('%s:chunk.%s', $jobName, ++$key),
                        function (JobRunner $jobRunner, Job $child) use ($body, $file, $key) {
                            $body['fileName'] = $file;
                            $body['options']['batch_number'] = $key;
                            $this->producer->send(
                                Topics::HTTP_IMPORT,
                                array_merge($body, ['jobId' => $child->getId()])
                            );
                        }
                    );
                }
                $context = $this->dependentJob->createDependentJobContext($job->getRootJob());
                $context->addDependentJob(
                    Topics::SEND_IMPORT_NOTIFICATION,
                    [
                        'rootImportJobId' => $job->getRootJob()->getId(),
                        'originFileName' => $body['originFileName'],
                        'userId' => $body['userId'],
                        'process' => $body['process'],
                    ]
                );
                $this->dependentJob->saveDependentJob($context);

                return true;
            }
        );
        $this->fileManager->deleteFile($body['fileName']);

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedTopics()
    {
        return [Topics::PRE_HTTP_IMPORT];
    }

    /**
     * {@inheritdoc}
     */
    protected function sendErrorNotification(array $body, $error)
    {
        $errorMessage = sprintf(
            '[PreHttpImportMessageProcessor] An error occurred while reading file %s: "%s"',
            $body['originFileName'],
            $error
        );

        $this->logger->critical($errorMessage, ['message' => $body]);

        $user = $this->managerRegistry
            ->getManagerForClass(User::class)
            ->getRepository(User::class)
            ->find($body['userId']);

        if (! $user instanceof User) {
            $this->logger->critical(
                sprintf('[PreHttpImportMessageProcessor] User not found. Id: %s', $body['userId']),
                ['body' => $body]
            );

            return;
        }

        $this->producer->send(NotifcationTopics::SEND_NOTIFICATION_EMAIL, [
            'fromEmail' => $this->configManager->get('oro_notification.email_notification_sender_email'),
            'fromName' => $this->configManager->get('oro_notification.email_notification_sender_name'),
            'toEmail' => $user->getEmail(),
            'template' => ImportExportResultSummarizer::TEMPLATE_IMPORT_ERROR,
            'body' => [
                'originFileName' => $body['originFileName'],
                'error' => $error,
            ],
            'contentType' => 'text/html',
        ]);
    }
}
