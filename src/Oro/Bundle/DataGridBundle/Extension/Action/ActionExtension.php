<?php

namespace Oro\Bundle\DataGridBundle\Extension\Action;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Translation\TranslatorInterface;

use Oro\Bundle\DataGridBundle\Datagrid\Common\MetadataObject;
use Oro\Bundle\DataGridBundle\Datagrid\Common\DatagridConfiguration;
use Oro\Bundle\DataGridBundle\Extension\AbstractExtension;
use Oro\Bundle\DataGridBundle\Extension\Action\Actions\ActionInterface;
use Oro\Bundle\DataGridBundle\Exception\RuntimeException;
use Oro\Bundle\SecurityBundle\SecurityFacade;

class ActionExtension extends AbstractExtension
{
    const METADATA_ACTION_KEY               = 'rowActions';
    const METADATA_ACTION_CONFIGURATION_KEY = 'action_configuration';

    const ACTION_KEY               = 'actions';
    const ACTION_CONFIGURATION_KEY = 'action_configuration';
    const ACTION_TYPE_KEY          = 'type';

    const ENABLE_ACTIONS_PARAMETER = '_enable_actions';

    /** @var ContainerInterface */
    protected $container;

    /** @var SecurityFacade */
    protected $securityFacade;

    /** @var TranslatorInterface */
    protected $translator;

    /** @var array */
    protected $actions = [];

    /** @var DatagridActionProviderInterface[] */
    protected $actionsProviders = [];

    /** @var array */
    protected static $excludeParams = [ActionInterface::ACL_KEY];

    /**
     * @param ContainerInterface $container
     * @param SecurityFacade $securityFacade
     * @param TranslatorInterface $translator
     */
    public function __construct(
        ContainerInterface $container,
        SecurityFacade $securityFacade,
        TranslatorInterface $translator
    ) {
        $this->container = $container;
        $this->securityFacade = $securityFacade;
        $this->translator = $translator;
    }

    /**
     * {@inheritDoc}
     */
    public function isApplicable(DatagridConfiguration $config)
    {
        if (!$this->getParameters()->get(static::ENABLE_ACTIONS_PARAMETER, true)) {
            return false;
        }

        $actionsProviders = $this->getApplicableActionProviders($config);

        return !empty($actionsProviders) || !empty($config->offsetGetOr(ActionExtension::ACTION_KEY));
    }

    /**
     * {@inheritDoc}
     */
    public function processConfigs(DatagridConfiguration $config)
    {
        foreach ($this->getApplicableActionProviders($config) as $provider) {
            $provider->applyActions($config);
        }
    }

    /**
     * @param DatagridConfiguration $config
     * @return array|DatagridActionProviderInterface[]
     */
    protected function getApplicableActionProviders(DatagridConfiguration $config)
    {
        return array_filter(
            $this->actionsProviders,
            function (DatagridActionProviderInterface $provider) use ($config) {
                return $provider->hasActions($config);
            }
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getPriority()
    {
        // should  be applied before formatter extension
        // this extension add dynamic property and this may cause a bug
        return 200;
    }

    /**
     * {@inheritDoc}
     */
    public function visitMetadata(DatagridConfiguration $config, MetadataObject $data)
    {
        $data->offsetAddToArray(static::METADATA_ACTION_KEY, $this->getActionsMetadata($config));
    }

    /**
     * @param DatagridConfiguration $config
     * @return array
     */
    protected function getActionsMetadata(DatagridConfiguration $config)
    {
        $actionsMetadata = [];
        $actions         = $config->offsetGetOr(static::ACTION_KEY, []);

        foreach ($actions as $name => $action) {
            $action = $this->getActionObject($name, $action);
            if ($action !== false) {
                $metadata          = $action->getOptions()->toArray([], static::$excludeParams);
                $metadata['label'] = isset($metadata['label'])
                    ? $this->translator->trans($metadata['label']) : null;

                $actionsMetadata[$action->getName()] = $metadata;
            }
        }

        return $actionsMetadata;
    }

    /**
     * @param  string $name
     * @param array   $config
     *
     * @return bool|ActionInterface
     */
    protected function getActionObject($name, array $config)
    {
        $config = ActionConfiguration::createNamed($name, $config);
        $action = $this->create($config);

        if (null === $action->getAclResource() || $this->isResourceGranted($action->getAclResource())) {
            return $action;
        }

        return false;
    }

    /**
     * Register action type
     *
     * @param string $type
     * @param string $serviceId
     *
     * @return $this
     */
    public function registerAction($type, $serviceId)
    {
        $this->actions[$type] = $serviceId;

        return $this;
    }

    /**
     * Creates and configure action object
     * Services are marked as scope: prototype
     *
     * @param ActionConfiguration $config
     *
     * @throws RuntimeException
     * @return ActionInterface
     */
    protected function create(ActionConfiguration $config)
    {
        if (!$config->offsetExists(static::ACTION_TYPE_KEY)) {
            throw new RuntimeException('The type must be defined');
        }

        $type = $config->offsetGet(static::ACTION_TYPE_KEY);
        if (!isset($this->actions[$type])) {
            throw new RuntimeException(
                sprintf('No attached service to action type named "%s"', $config->offsetGet(static::ACTION_TYPE_KEY))
            );
        }

        $actionServiceId = $this->actions[$type];

        /** @var $action ActionInterface */
        $action = $this->container->get($actionServiceId);
        $action->setOptions($config);

        return $action;
    }

    /**
     * Checks if an access to a resource is granted or not
     *
     * @param string $aclResource An ACL annotation id or "permission;descriptor"
     *
     * @return bool
     */
    protected function isResourceGranted($aclResource)
    {
        $delimiter = strpos($aclResource, ';');
        if ($delimiter) {
            $permission = substr($aclResource, 0, $delimiter);
            $descriptor = substr($aclResource, $delimiter + 1);

            return $this->securityFacade->isGranted($permission, $descriptor);
        }

        return $this->securityFacade->isGranted($aclResource);
    }

    /**
     * @param DatagridActionProviderInterface $actionsProvider
     */
    public function addActionProvider(DatagridActionProviderInterface $actionsProvider)
    {
        $this->actionsProviders[] = $actionsProvider;
    }
}
