<?php

namespace CustomersBundle\Task\Customer;

use Amo\Crm\SupportApiClient;
use Amo\Element;
use Amo\Note;
use CustomersBundle\Helpers\Helper;
use CustomersBundle\Task\BaseTask;
use CustomersBundle\Tool\CustomerUpdateTool;
use Symfony\Component\DependencyInjection\ContainerInterface;


use Phase\AmoCRM_API;
use RuntimeException;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Amo\Crm\ApiException;
use CustomersBundle\Customer\AccountUpdate;

class AccountUpdateTask extends BaseTask
{
    const QUEUE_NAME = 'account_update';

    /**
     * {@inheritDoc}
     */
    public function setContainer(ContainerInterface $container = null)
    {
        parent::setContainer($container);
    }

    /**
     * {@inheritDoc}
     */
    public function execute(array $data)
    {
        sleep(5);
        try{
            $logger = $this->getLogger();

            $this->log('data', $data);

            if (empty($data['event']['account_id'])) {
                $logger->debug('Account id is empty');
                $this->log('Account id is empty');
                return;
            }

            $account_id = $data['event']['account_id'];
            $shard_type = Helper::getAccountShardType($account_id);

            /** @var SupportApiClient $supportApi */
            $supportApi = $this->container->get('amocrm.support_api');

            /** @var CustomerUpdateTool $updateTool */
            $updateTool = $shard_type === 2 ?
                $this->container->get('customersus.customer_update_tool') :
                $this->container->get('customers.customer_update_tool');

            /** @var AmoCRM_API $api */
            $api = $shard_type === 2 ?
                $this->container->get('amocrm.customersus_api') :
                $this->container->get('amocrm.customers_api');

            $accountApdate = new AccountUpdate($api, $supportApi, $updateTool, $logger, $this->getOutput());

            $accountApdate->handleAccountUpdate($data['data'],$data['event']);
        }catch(RuntimeException $e){

            $logger->error($e->getMessage(), $data);
            throw $e;
        }
    }


    /**
     * @return LoggerInterface
     */
    protected function getLogger()
    {
        return  $this->container->get('monolog.logger.account_update');
    }

}

