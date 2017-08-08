<?php                

class Limelight extends PluginInterface            
{                    

	public $shipMethods = array();

	function importAllOrders($startDate,$endDate=NULL,$orderType='all')
	{
		$server = Appication::getInstance()->getClientServer();
		
		$orderIds = $this->fetchOrdersList($startDate,$endDate,$orderType);	
	
	
		if(!empty($endDate))
			echo "GETTING ORDERS FROM ".$startDate->format('Y-m-d')." TO ".$endDate->format('Y-m-d')."\n";
		else
			echo "GETTING ORDER FOR DATE: ".$startDate->format('Y-m-d')."\n";
		echo "GOT ".count($orderIds)." TO IMPORT:\n";
		
		if(count($orderIds) == 0)
			return;
		
		$total = count($orderIds);
		$stime = microtime(true);

		ob_flush();
		
		if(count($orderIds) > 200)
			$batches = arrays::chunk($orderIds,200);
		else
			$batches = array($orderIds);

		foreach($batches as $batch)
		{
			$orderIds = implode(",",$batch);
			$orders = $this->fetchOrdersArr($orderIds);
			foreach($orders as $order)
			{
				$this->createOrder($order);
			}
		}
		
		$etime = microtime(true);
		$totaltime = ($etime - $stime);
		echo "TOTAL TIME: ".number_format($totaltime,2)."s"."\n";;
		$avg = $total / $totaltime;
		
		echo "AVG PER SEC:".$avg."\n\n\n";
	}


	function fetchOrdersList($startDate,$endDate=NULL,$orderType='all')
	{
		$params = (object) array();
		
		if(!is_a($startDate,'DateTime'))
			throw new Exception("startDate must be a datetime");
			
		if(empty($endDate))
			$endDate = clone $startDate;
			
		if(!is_a($endDate,'DateTime'))
			throw new Exception("endDate must be a datetime");	
		
		$params->username = $this->username;
		$params->password = $this->password;
		$params->method = 'order_find';
		$params->campaign_id = 'all';
		$params->product_id = 'all';
		$params->start_date = $startDate->format("m/d/y");
		$params->end_date = $endDate->format("m/d/y");
		$params->search_type = 'all';
		$params->criteria = $orderType;
	
		$url = 'https://'.$this->domain.'/admin/membership.php';
		$headers = array();
		$headers[] = 'Content-Type: application/x-www-form-urlencoded';
		
		$request = new HttpRequest($url);
		$request->headers = $headers;
		$request->method = 'POST';
		$request->body = http_build_query($params);
				
		$response = HttpClient::sendRequest($request);
		
		parse_str($response->body,$retObj);
		
		$retObj = (object) $retObj;
		
		
		if($retObj->response_code != 100)
		{
			if($retObj->response_code == 333)
				return array();
			
			var_dump($retObj);
			
			throw new Exception($retObj->response_code.": ".$retObj->error_message);
		}
		if(empty($retObj->order_ids))
			return array();
		
		$orderIds = explode(",",$retObj->order_ids);

		return $orderIds;
	}
	
	
	function fetchCreateOrder($orderIds)
	{
		$arr = $this->fetchOrdersArr($orderIds);
				
		if(!empty($arr))
		{
			foreach($arr as $order)
				$this->createOrder($order);
		}
	}
	


	function fetchOrdersArr($orderIds)
	{
		if(empty($orderIds))
			throw new Exception("No orderIds provided");
		
		$singleOrder = false;
		if(strpos($orderIds,",") === false)
		{
			$singleOrder = true;
			$id = $orderIds;
		}
				
		$params = (object) array();
		
		$params->username = $this->username;
		$params->password = $this->password;
		$params->method = 'order_view';
		$params->order_id = $orderIds;
		$params->return_format = 'json';
		
		$url = 'https://'.$this->domain.'/admin/membership.php';
		$headers = array();
		$headers[] = 'Content-Type: application/x-www-form-urlencoded';
		
		$request = new HttpRequest($url);
		$request->headers = $headers;
		$request->method = 'POST';
		$request->body = http_build_query($params);
		
		$response = HttpClient::sendRequest($request);
		
		if(!empty($request->curlErrorNo))
		{
			echo "GOT ERROR NO. $request->curlErrorNo : $request->curlError\nSleeping for 30 seconds and trying again\n";
			//try again if limelight throttles request
			sleep(30);
			$request = new HttpRequest($url);
			$request->headers = $headers;
			$request->method = 'POST';
			$request->body = http_build_query($params);
			
			$response = HttpClient::sendRequest($request);
		}
		
		$result = $response->body;
		
		
		if(is_object($result) && $singleOrder == true)
		{
			if(is_string($result))
				$result = json_decode($result);
	
			$result->orderId = $id;	
			$result = array($result);
		}
		elseif($singleOrder == false)
		{
			if(is_string($result->data))
				$result = (array) json_decode($result->data);
			else
				$result = (array) $result;
				
			foreach($result as $orderId=>$obj)
			{
				$obj->orderId = $orderId;
			}
		}
		else
		{
			throw new Exception("Response is nonsense");	
		}
		
		foreach($result as $row)
		{
			
			
			if(is_array($row->products))
			{
				foreach($row->products as $i=>$obj)
				{
					foreach((array) $obj as $k=>$v)
						$row->{"products[$i][$k]"} = $v;
				}
				unset($row->products);
			}
			
			foreach(array('systemNotes','employeeNotes') as $val)
			{
				if(isset($row->$val) && is_array($row->$val))
				{
					foreach($row->$val as $i=>$v)
					{
						$row->{$val."[$i]"} = $v;
					}
				}
				unset($row->$val);
			}
			
			
			
			
			foreach($row as &$v)
			{
				$v = urldecode($v);
			}
			unset($v);
		}
	
		return $result;
	}

	function createProduct($limelightProductId,$subscriptionArr=NULL)
	{
		
		$app = Appication::getInstance();
		$server = $app->getClientServer();
		
		$params = (object) array();
		
		$params->username = $this->username;
		$params->password = $this->password;
		$params->method = 'product_index';
		$params->product_id = $limelightProductId;
	
		$url = 'https://'.$this->domain.'/admin/membership.php';
		$headers = array();
		$headers[] = 'Content-Type: application/x-www-form-urlencoded';
		
		$request = new HttpRequest($url);
		$request->headers = $headers;
		$request->method = 'POST';
		$request->body = http_build_query($params);
		
		$response = HttpClient::sendRequest($request);
		$this->logRequest($request);
		
		$text = $response->body;
		
		$arr = explode('&',$text);
		
		$newArr = array();
		
		foreach($arr as $kvpair)
		{
			$pos = strpos($kvpair,'=');
			$k = substr($kvpair,0,$pos);
			$v = urldecode(substr($kvpair,$pos+1));

			$newArr[$k] = $v;	
		}
	
		$newArr = (object) $newArr;
		
		$categoryName = $newArr->product_category_name;
		$sql = "SELECT productCategoryId FROM product_categories WHERE categoryName = ?";
		$productCategoryId = $server->fetchValue($sql,$categoryName);
		
		$clientProductId = $params->product_id;
		if(empty($subscriptionArr))
		{
			$sql = "SELECT productId FROM products WHERE clientProductId = ?";
			$productId = $server->fetchValue($sql,$clientProductId);
		}
		else
		{
			$sql = "SELECT productId FROM products WHERE clientProductId IN(:vals)";
			$productId = $server->fetchValue($sql,$subscriptionArr);
		}
				
		if(empty($productCategoryId))
		{
			$category = new ProductCategory;
			$category->categoryName = $categoryName;	
			$productCategoryId = $category->create();
		}
		
		if(empty($productId))
		{	
	
			$product = new Product;
			
			$product->clientProductId = $clientProductId;
			$product->fulfillmentCycleType = 'NO_SHIPPING';
			$product->productName = $newArr->product_name;
			$product->productSku = $newArr->product_sku;
			$product->productQty = $newArr->product_max_quantity;
			$product->productPrice = $newArr->product_price;
			$product->productCost = '0.00';
			$product->shippingCost = '0.00';
			$product->productDescription = $newArr->product_description;
			$product->productCategoryId = $productCategoryId;
		
			$productId = $product->create();
		}
	
		$newArr->productId = $productId;
	
		return $newArr;
	}
	
	function createCampaign($limelightCampaignId)
	{
		
		$app = Application::getInstance();
		$server = $app->getClientServer();
		
		$params = (object) array();
		
		$params->username = $this->username;
		$params->password = $this->password;
		$params->method = 'campaign_view';
		$params->campaign_id = $limelightCampaignId;
		
		$url = 'https://'.$this->domain.'/admin/membership.php';
		$headers = array();
		$headers[] = 'Content-Type: application/x-www-form-urlencoded';
		
		$request = new HttpRequest($url);
		$request->headers = $headers;
		$request->method = 'POST';
		$request->body = http_build_query($params);
		
		$response = HttpClient::sendRequest($request);
		$this->logRequest($request);
		
		$text = $response->body;
		
		$arr = explode('&',$text);
		
		$newArr = array();
		
		foreach($arr as $kvpair)
		{
			$kv = explode('=',$kvpair);
			$k = $kv[0];
			$v = urldecode($kv[1]);
			
			$newArr[$k] = $v;
		}
		
		$newArr = (object) $newArr;

		$sql = "SELECT externalCampaignId FROM campaigns WHERE externalCampaignId = ?";
		$campaignId = $server->fetchValue($sql,$limelightCampaignId);
		
		if(empty($campaignId))
		{
			$campaign = new Campaign;

			$campaign->campaignName = $newArr->campaign_name;
			$campaign->externalCampaignId = $limelightCampaignId;
			$campaign->currency = 'USD';
			$campaign->campaignType = 'LANDER';
			
			$campaignId = $campaign->create();
			
			return $campaignId;
		}
			
	}
	
	function getShipMethod($shippingId)
	{
		if($shippingId == 0)
		{
			return (object) array('shipPrice'=>0,
						 		  'shipCarrier'=>NULL);
		}
		
		$params = (object) array();

		$params->username = $this->username;
		$params->password = $this->password;
		$params->method = 'shipping_method_view';
		$params->shipping_id = $shippingId;
			
		$url = 'https://'.$this->domain.'/admin/membership.php';
		$headers = array();
		$headers[] = 'Content-Type: application/x-www-form-urlencoded';
		
		$request = new HttpRequest($url);
		$request->headers = $headers;
		$request->method = 'POST';
		$request->body = http_build_query($params);
		
		$response = HttpClient::sendRequest($request);
		
		if(!empty($request->curlErrorNo))
		{
			sleep(30);
			$request = new HttpRequest($url);
			$request->headers = $headers;
			$request->method = 'POST';
			$request->body = http_build_query($params);
			
			$response = HttpClient::sendRequest($request);
		}
		
		if(!empty($request->curlErrorNo))
		{
			throw new Exception("Limelight sux dix everyday");
		}
		
		
		parse_str($response->body,$shipRet);
		
		$shipRet = (object) $shipRet;
		
		$storeThis = (object) array();
		$storeThis->shipPrice = $shipRet->initial_amount;
		$storeThis->shipCarrier = $shipRet->group_name;
		
		$this->shipMethods[$shippingId] = $storeThis;
		
		
		return $this->shipMethods[$shippingId];
		
	}
	
	function createOrder($llOrder)
	{
		echo "CREATE ORDER: ".$llOrder->orderId."\n";
		$app = Application::getInstance();
		$server = $app->getClientServer();
				
		if($llOrder->response_code != 100)
		{
			dump_var($llOrder);
			throw new Exception("orderId: $orderId does not exist in Limelight");
		}
		if($llOrder->customer_id == 0)
			$limeLightCustomerId = $llOrder->orderId;
		else
			$limeLightCustomerId = $llOrder->customer_id;
			
		if(empty($limeLightCustomerId))
		{
			var_dump($llOrder);
			throw new Exception("No customerId");
		}
		
		$sql = "SELECT orderId FROM orders WHERE clientOrderId = ?";
		$hasOrder = $server->fetchExists($sql,$llOrder->orderId);

		if($hasOrder)
			return;
		
		$customer = new Customer;
		$order = new CustomerOrder;
		$transaction = new Transaction;
		$transactionItem = new TransactionItem;
		$orderItem = new OrderItem;
	
		if(!empty($llOrder->billing_cycle))
		{
			if($llOrder->billing_cycle == 0)
				$llOrder->billing_cycle = 1;
		}
		
		if(!empty($llOrder->order_status))
		{
			if($llOrder->order_status == '2' || $llOrder->order_status == '8')
			{
				$transaction->responseType = 'SUCCESS';
				$transactionItem->refundRemaining = $llOrder->order_total;				

				if($llOrder->on_hold == '1')
					$order->orderStatus = 'CANCELLED';
				else
					$order->orderStatus = 'COMPLETE';	
			}
			elseif($llOrder->order_status == '6')
			{
				$order->orderStatus = 'REFUNDED';
				$transaction->responseType = 'SUCCESS';
				$transactionItem->refundRemaining = '0.00';
			}
			elseif($llOrder->order_status == '7')
			{
				$order->orderStatus = 'DECLINED';
				$transaction->responseType = 'SOFT_DECLINE';
				$transactionItem->refundRemaining = $llOrder->order_total;
			}
		}
		
		if($llOrder->billing_cycle > 1)
			$order->orderType = 'RECURRING';
		else
			$order->orderType = 'NEW_SALE';
		
		if(!empty($llOrder->cc_type))
		{
			if($llOrder->cc_type == 'visa')
				$llOrder->cc_type = 'VISA';
			
			elseif($llOrder->cc_type == 'master')
				$llOrder->cc_type = 'MASTERCARD';
			
			elseif($llOrder->cc_type == 'discover')
				$llOrder->cc_type = 'DISCOVER';
			
			elseif($llOrder->cc_type == 'amex')
				$llOrder->cc_type = 'AMEX';
		}
		
		if(empty($llOrder->upsell_product_id) && empty($llOrder->upsell_product_quantity))
			$transactionItem->productType = 'OFFER';	
		else
			$transactionItem->productType = 'UPSALE';
		
		$shipMethod = $this->getShipMethod($llOrder->shipping_id);
		$shipPrice = $shipMethod->shipPrice;
		$shipCarrier = $shipMethod->shipCarrier;
		

		if(!empty($shipPrice) && !empty($shipCarrier))
		{
			$order->shipCarrier = $shipCarrier;
			$order->shippingPrice = $shipPrice;
			$order->baseShipping = $shipPrice;
			$orderItem->shipPrice = $shipPrice;	
		}
		else
		{
			$order->shipCarrier = NULL;
			$order->shippingPrice = '0.00';
			$order->baseShipping = '0.00';
			$orderItem->shipPrice = '0.00';
		}
		
		$limelightCampaignId = $llOrder->campaign_id;
		
		$sql = "SELECT campaignId FROM campaigns WHERE externalCampaignId = ?";
		$campaignId = $server->fetchValue($sql,$limelightCampaignId);
		
		if(empty($limelightCampaignId))
			throw new ValidationException("Order must include a campaign");
		
		if(!$campaignId)
		{
			$campaignId = $this->createCampaign($limelightCampaignId);
		}

		$limelightProductId = $llOrder->{'products[0][product_id]'};
		$subscriptionId = $llOrder->{'products[0][subscription_id]'};
		
		$subscriptionArr = NULL;
		if(!empty($subscriptionId))
		{
			if(empty($subscriptions[$subscriptionId]))
			{
				$subscriptions[$subscriptionId] = array($limelightProductId);	
			}
			else
			{
				if(empty($subscriptions))
				{
					$subscriptions[$subscriptionId] = array($limelightProductId);
				}
				
				$subscriptions[$subscriptionId][] = $limelightProductId;
			}
		}	
		
		$subscriptionArr = $subscriptions[$subscriptionId];
				
		$sql = "SELECT clientCustomerId FROM customers WHERE clientCustomerId = ?";
		$clientCustomerId = $server->fetchValue($sql,$llOrder->customer_id);
		
		if(empty($clientCustomerId))
		{
			$sql = "SELECT clientOrderId FROM orders WHERE clientOrderId = ?";
			$clientOrderId = $server->fetchValue($sql,$llOrder->orderId);	
		}
		
		if(!in_array($llOrder->cc_type,array('VISA','AMEX','MASTERCARD','DISCOVER')))
			$llOrder->cc_type = NULL;
		

		if($clientCustomerId != $llOrder->customer_id || empty($clientOrderId))
		{
			
			$customer->clientCustomerId = $llOrder->customer_id == 0 ? NULL : $llOrder->customer_id;
			$customer->firstName = $llOrder->first_name;
			$customer->lastName = $llOrder->last_name;
			$customer->address1 = $llOrder->billing_street_address;
			$customer->address2 = $llOrder->billing_street_address2;
			$customer->city = $llOrder->billing_city;
			$customer->state = $llOrder->billing_state;
			$customer->postalCode = $llOrder->billing_postcode;
			$customer->country = $llOrder->billing_country;
			$customer->shipFirstName = $llOrder->shipping_first_name;
			$customer->shipLastName = $llOrder->shipping_last_name;
			$customer->shipAddress1 = $llOrder->shipping_street_address;
			$customer->shipAddress2 = $llOrder->shipping_street_address2;
			$customer->shipCity = $llOrder->shipping_city;
			$customer->shipState = $llOrder->shipping_state;
			$customer->shipPostalCode = $llOrder->shipping_postcode;
			$customer->shipCountry = $llOrder->shipping_country;
			$customer->phoneNumber = $llOrder->customers_telephone;
			$customer->emailAddress = $llOrder->email_address;
			$customer->cardType = $llOrder->cc_type;
			$customer->cardNumber = $llOrder->cc_number;
			$customer->ipAddress = $llOrder->ip_address;
			$customer->campaignId = $campaignId;
			$customer->dateCreated = $llOrder->time_stamp;

			$customer->create();
			
			$order->updateValues($customer);		
			$order->clientOrderId = $llOrder->orderId;
			$order->dateCreated = $llOrder->time_stamp;
			$order->price = $llOrder->{'products[0][price]'};
			$order->salesTax = $llOrder->order_sales_tax_amount;
			$order->basePrice = $llOrder->{'products[0][price]'};
			$order->totalAmount = $llOrder->order_total;
			$order->orderValue = $llOrder->order_total;
			$order->externalPluginId = $this->pluginId;
			$order->paySource = 'CREDITCARD';

			$order->create();
		
		}
		else
		{

			$sql = "SELECT customerId FROM customers WHERE clientCustomerId = ?";
			$customerOrderId = $server->fetchValue($sql,$clientCustomerId); 
					
			$order->customerId = $customerOrderId;
			$order->clientCustomerId = $clientCustomerId;
			$order->firstName = $llOrder->first_name;
			$order->lastName = $llOrder->last_name;
			$order->address1 = $llOrder->billing_street_address;
			$order->address2 = $llOrder->billing_street_address2;
			$order->city = $llOrder->billing_city;
			$order->state = $llOrder->billing_state;
			$order->postalCode = $llOrder->billing_postcode;
			$order->country = $llOrder->billing_country;
			$order->shipFirstName = $llOrder->shipping_first_name;
			$order->shipLastName = $llOrder->shipping_last_name;
			$order->shipAddress1 = $llOrder->shipping_street_address;
			$order->shipAddress2 = $llOrder->shipping_street_address2;
			$order->shipCity = $llOrder->shipping_city;
			$order->shipState = $llOrder->shipping_state;
			$order->shipPostalCode = $llOrder->shipping_postcode;
			$order->shipCountry = $llOrder->shipping_country;
			$order->phoneNumber = $llOrder->customers_telephone;
			$order->emailAddress = $llOrder->email_address;
			$order->cardType = $llOrder->cc_type;
			$order->cardNumber = $llOrder->cc_number;
			$order->cardLast4 = substr($order->cardNumber,-4);
			$order->paySource = 'CREDITCARD';
			$order->ipAddress = $llOrder->ip_address;
			$order->campaignId = $campaignId;
			$order->clientOrderId = $llOrder->orderId;
			$order->dateCreated = $llOrder->time_stamp;
			$order->price = $llOrder->{'products[0][price]'};
			$order->salesTax = $llOrder->order_sales_tax_amount;
			$order->basePrice = $llOrder->{'products[0][price]'};
			$order->totalAmount = $llOrder->order_total;
			$order->orderValue = $llOrder->order_total;
			$order->externalPluginId = $this->pluginId;

			$order->create();

		}
		
		$transaction->updateValues($order);
		$transaction->transactionType = 'SALE';
		$transaction->currency = 'USD';
		$transaction->authCode = $llOrder->auth_id;
		$transaction->merchantTransactionId = $llOrder->transaction_id;
		$transaction->responseText = $llOrder->decline_reason;
		$transaction->billerId = NULL;
		$transaction->dateCreated = $llOrder->time_stamp;
		
		$transaction->create();

		if(empty($limelightProductId))
			throw new ValidationException("Order must include a product");
		
		//init to null
		$campaignProduct = NULL;
		$llProduct = NULL;
		
		//find productId associated with limelight product
		$sql = "SELECT productId FROM products WHERE clientProductId = ?";
		$productId = $server->fetchValue($sql,$limelightProductId);
		
		if(empty($productId))
		{
			$llProduct = $this->createProduct($limelightProductId,$subscriptionArr);
			
			if(empty($llProduct))
				throw new Exception("WTF no product returned by Limelight");
			
			$productId = $llProduct->productId;
		}
		
		$args = new QueryArgs;
		$args->campaignId = $campaignId;
		$args->productId = $productId;
		$campaignProduct = CampaignProduct::fetch($args);
	
		
		//if we don't have campaignProduct create it by querying limelight for product data
		if(empty($campaignProduct))
		{
			//$sql = "SELECT billerId FROM billers LIMIT 1";
			$billerId = 0;
			
			$campaignProduct = new CampaignProduct;

			$campaignProduct->updateValues($transactionItem);
			$campaignProduct->campaignId = $campaignId;
			$campaignProduct->productId = $productId;
			$campaignProduct->billerId = $billerId;
			$campaignProduct->displayName = $llOrder->{'products[0][name]'};
			$campaignProduct->price = $llOrder->{'products[0][price]'};
			$campaignProduct->billingIntervalDays = $llProduct->product_rebill_days;
			$campaignProduct->dateCreated = $llOrder->time_stamp;

			if($shipPrice)
				$campaignProduct->shippingPrice = $shipPrice;
			else
				$campaignProduct->shippingPrice = '0.00';
			
			if(empty($llOrder->upsell_product_id) && empty($llOrder->upsell_product_quantity))
				$campaignProduct->productType = 'OFFER';	
			else
				$campaignProduct->productType = 'UPSALE';
			
			if($llProduct->product_is_trial == '1' && $llProduct->product_rebill_days != 0)
			{
				$campaignProduct->trialEnabled = '1';
				$campaignProduct->trialType = 'DELAYED';
				$campaignProduct->trialPrice = $llOrder->{'products[0][price]'};
				$campaignProduct->trialBillerId = $billerId;
				$campaignProduct->trialPeriodDays = $llProduct->product_rebill_days;

				if($shipPrice)
					$campaignProduct->trialShippingPrice = $shipPrice;
				else
					$campaignProduct->trialShippingPrice  = '0.00';
			}
			else
				$campaignProduct->trialEnabled = '0';	

			if(!empty($llProduct->product_rebill_product))
				$campaignProduct->billingCycleType = 'RECURRING';
			else
				$campaignProduct->billingCycleType = 'ONE_TIME';
							
			$campaignProduct->create();
		}
								
		$campaignProduct->updateValues($transactionItem);

		$orderItem->updateValues($campaignProduct);
		$orderItem->orderId = $order->orderId;
		
		if($shipPrice)
			$orderItem->shipPrice = $shipPrice;
		else
			$orderItem->shipPrice = '0.00';
		
		$orderItem->create();
		
		if($llOrder->shipping_method_name != 'NA' && $llOrder->order_status != '7')
		{
			
			$fulfillment = new Fulfillment;
			$fulfillment->clientFulfillmentId = $order->clientOrderId;
			$fulfillment->orderId = $order->orderId;
			$fulfillment->customerId = $order->customerId;
			$fulfillment->dateCreated = $order->dateCreated;
			
			if(!empty($llOrder->tracking_number))
			{
				$fulfillment->status = 'SHIPPED';
				$fulfillment->trackingNumber = $llOrder->tracking_number;	
				$fulfillment->shipCarrier = $shipCarrier;
			}
			elseif(!empty($llOrder->rma_number))
			{
				$fulfillment->status = 'RETURNED';
				$fulfillment->rmaNumber = $llOrder->rma_number;
			}
			else
				$fulfillment->status = 'PENDING';	
			
			$fulfillment->create();
			
			$fulfillmentItem = new FulfillmentItem;
			
			$fulfillmentItem->fulfillmentId = $fulfillment->fulfillmentId;
			$fulfillmentItem->status = $fulfillment->status;
			$fulfillmentItem->orderItemId = $orderItem->orderItemId;
			$fulfillmentItem->productQty = $llOrder->{'products[0][product_qty]'};
			$fulfillmentItem->productSku = $llOrder->{'products[0][sku]'};
			$fulfillmentItem->productName = $llOrder->{'products[0][name]'};
			
			$fulfillmentItem->create();
			
		}
		
		if($campaignProduct->billingCycleType == 'RECURRING')
		{

			$sql = "SELECT purchaseId FROM recurring_purchases WHERE clientPurchaseId = ?";
			$purchaseId = $server->fetchValue($sql,$subscriptionId);
			
			if($llOrder->order_status != '7')
			{								
				$recurringPurchase = new RecurringPurchase;
				
				$compare = date("m/d/y",strtotime($llOrder->recurring_date)) > date('m/d/y');
				
				if($order->orderStatus == 'CANCELLED')
					$recurringPurchase->status = 'CANCELLED';
				elseif($compare == false)
					$recurringPurchase->status = 'INACTIVE';
				else
					$recurringPurchase->status = 'ACTIVE';
				
				$recurringPurchase->clientPurchaseId = $subscriptionId;
				$recurringPurchase->campaignId = $campaignId;
				$recurringPurchase->productId = $productId;
				$recurringPurchase->customerId = $order->customerId;
				$recurringPurchase->orderId = $order->orderId;
				$recurringPurchase->billerId = NULL;
				$recurringPurchase->price = $campaignProduct->price;
				$recurringPurchase->salesTax = $llOrder->order_sales_tax;
				$recurringPurchase->totalAmount = $llOrder->order_total;
				$recurringPurchase->trialEnabled = $campaignProduct->trialEnabled;
				$recurringPurchase->trialComplete = '0';
				$recurringPurchase->billingCycleType = 'RECURRING';
				$recurringPurchase->billingCycleNumber = $llOrder->billing_cycle < 1 ? 1 : $llOrder->billing_cycle;
				$recurringPurchase->billingIntervalDays = $campaignProduct->billingIntervalDays;
				$recurringPurchase->finalBillingCycle = '0';
				$recurringPurchase->fufillmentCycleType = 'RECURRING';
				$recurringPurchase->fulfillmentCycle = '0';
				$recurringPurchase->recycleBillingNumber = '0';
				$recurringPurchase->nextBillDate = $llOrder->recurring_date;
				$recurringPurchase->productQty = '1';
				$recurringPurchase->dateCreated = $llOrder->time_stamp;
				$recurringPurchase->orderId = $order->orderId;
				$recurringPurchase->trialType = 'DELAYED';
				$recurringPurchase->regularPrice = $campaignProduct->price;
				$recurringPurchase->forceRebills = '0';
				$recurringPurchase->delayBill2 = '0';
				$recurringPurchase->originalBillerId = NULL;
				$recurringPurchase->insureShipments = '0';
				$recurringPurchase->lastFulfillmentCaptured = '0';
				$recurringPurchase->customTaxSet = '0';
				
				if($shipPrice)
					$recurringPurchase->shippingPrice = $shipPrice;
				else
					$recurringPurchase->shippingPrice = '0.00';
				
				if(empty($purchaseId))
				{
					$recurringPurchase->create();
				}
				else
				{
					$recurringPurchase->purchaseId = $purchaseId;
					$recurringPurchase->update();
				}
				
			}
			
		}

		$transactionItem->updateValues($transaction);
		$transactionItem->productId = $productId;
		$transactionItem->price = $llOrder->{'products[0][price]'};
		$transactionItem->productName = $llOrder->{'products[0][name]'};
		$transactionItem->productSku = $llOrder->{'products[0][sku]'};
		$transactionItem->productQty = $llOrder->{'products[0][product_qty]'};
		$transactionItem->billingCycleType = $campaignProduct->billingCycleType; 
		$transactionItem->billingCycleNumber = $llOrder->billing_cycle < '1' ? '1' : $llOrder->billing_cycle;
		$transactionItem->recycleBillingNumber = '0';
		$transactionItem->productCost = '0.00';
		$transactionItem->shipDiscount = '0.00';
		$transactionItem->shipUpcharge = '0.00';
		$transactionItem->discountPrice = '0.00';
		
		if(!empty($recurringPurchase))
			$transactionItem->purchaseId = $recurringPurchase->purchaseId;
			
		$transactionItem->create();
		
		$sql = "SELECT orderItemId FROM order_items WHERE orderId = ?";
		$orderItemId = $server->fetchValue($sql,$order->orderId);
		
		if(!empty($orderItemId))
		{
			$orderItem->updateValues($campaignProduct);
			$orderItem->orderId = $order->orderId;
			$orderItem->transactionItemId = $transactionItem->transactionItemId;
			$orderItem->update();	
		}

		for($i = 0; $i < 50; $i++)
		{
			$continueLoop = false;
			if(isset($llOrder->{"employeeNotes[$i]"}))
			{
				
				$employeeHyphen = strpos($llOrder->{"employeeNotes[$i]"},'-');
				$employeeDate = substr($llOrder->{"employeeNotes[$i]"},0,$employeeHyphen-1);
				$employeeHyphenPos = strpos($llOrder->{"employeeNotes[$i]"},'-');
				$employeeLastHyphenPos = substr($llOrder->{"employeeNotes[$i]"},$employeeHyphenPos+2);
				$employeeLastHyphen = strpos($employeeLastHyphenPos,'-');
				$employeeNote = substr($employeeLastHyphenPos,$employeeLastHyphen+2);

				$customerHistory = new CustomerHistory;
				$customerHistory->customerId = $order->customerId;
				$customerHistory->dateCreated = new DateTime($employeeDate);
				$customerHistory->externalNoteId = $order->orderId.'employee'."$i";
				
				$message = substr($employeeNote,0);
				$message = substr($message,-1) != '.' ? $message.'.' : $message;
				$customerHistory->message = $message;
				$customerHistory->create();
				$continueLoop = true;
			}
			
			if(isset($llOrder->{"systemNotes[$i]"}))
			{
				
				$systemHyphen = strpos($llOrder->{"systemNotes[$i]"},'-');
				$systemDate = substr($llOrder->{"systemNotes[$i]"},0,$systemHyphen-1);
				$systemHyphenPos = strpos($llOrder->{"systemNotes[$i]"},'-');
				$systemLastHyphenPos = substr($llOrder->{"systemNotes[$i]"},$systemHyphenPos+2);
				$systemLastHyphen = strpos($systemLastHyphenPos,'-');
				$systemNote = substr($systemLastHyphenPos,$systemLastHyphen+2);
			
				$customerHistory = new CustomerHistory;
				$customerHistory->customerId = $order->customerId;
				$customerHistory->dateCreated = new DateTime($systemDate);
				$customerHistory->externalNoteId = $order->orderId.'system'."$i";
				
				$message = substr($systemNote,0);
				$message = substr($message,-1) != '.' ? $message.'.' : $message;
				$customerHistory->message = $message;
				$customerHistory->create();
				$continueLoop = true;
			}
			
			if(!$continueLoop)
				break;
		}
				
	}
			
	function updateOrder()
	{
		
		$app = Application::getInstance();
		$server = $app->getClientServer();
		
		$params = (object) array();
		
		$params->username = $this->username;
		$params->password = $this->password;
		$params->method = 'order_find_updated';
		$params->campaign_id = 'all';
		$params->start_date = '01/01/1990';
		$params->end_date = date('m/d/Y');
		
		$url = 'https://'.$this->domain.'/admin/membership.php';
		$headers = array();
		$headers[] = 'Content-Type: application/x-www-form-urlencoded';
		
		$request = new HttpRequest($url);
		$request->headers = $headers;
		$request->method = 'POST';
		$request->body = http_build_query($params);
		
		$response = HttpClient::sendRequest($request);
		$this->logRequest($request);
		
		$text = $response->body;
		$pos = strrpos($text,'=');
		$orderIds = substr($text,0,$pos);
		
		$lastPos = strrpos($orderIds,'=');
		$subOrderIds = substr($orderIds,$lastPos+1);
		$subOrderIds = substr($subOrderIds,0,-5);
		$orderIdArr = explode(',',$subOrderIds);
	
		$updates = substr($text,$pos+1);
		$updateArr = json_decode($updates);		

		foreach($orderIdArr as $llOrderId)
		{
			
			$args = new QueryArgs;
			$args->clientOrderId = $llOrderId;			
			$orderObj = CustomerOrder::fetch($args);
			
			if($orderObj != false)
			{
					
				$params = (object) array();
		
				$params->username = $this->username;
				$params->password = $this->password;
				$params->method = 'order_view';
				$params->order_id = $llOrderId;

				$url = 'https://'.$this->domain.'/admin/membership.php';
				$headers = array();
				$headers[] = 'Content-Type: application/x-www-form-urlencoded';
				
				$request = new HttpRequest($url);
				$request->headers = $headers;
				$request->method = 'POST';
				$request->body = http_build_query($params);
				
				$response = HttpClient::sendRequest($request);
				$this->logRequest($request);
				
				$text = $response->body;
				
				$arr = explode('&',$text);
				
				$newArr = array();
				
				foreach($arr as $kvpair)
				{
					$kv = explode('=',$kvpair);
					$k = $kv[0];
					$v = urldecode($kv[1]);
						
					$newArr[$k] = $v;
				}
				
				$newArr = (object) $newArr;
				
				$args->customerId = $orderObj->customerId;
				$customerObj = Customer::fetch($args);
				
				$params = (object) array();

				$params->username = $this->username;
				$params->password = $this->password;
				$params->method = 'shipping_method_view';
				$params->shipping_id = $newArr->shipping_id;
					
				$url = 'https://'.$this->domain.'/admin/membership.php';
				$headers = array();
				$headers[] = 'Content-Type: application/x-www-form-urlencoded';
				
				$request = new HttpRequest($url);
				$request->headers = $headers;
				$request->method = 'POST';
				$request->body = http_build_query($params);
				
				$response = HttpClient::sendRequest($request);
				$this->logRequest($request);
				
				$body = $response->body;
				$shipBody = explode('&',$body);
				
				$shipArr = array();
				foreach($shipBody as $kvpair)
				{
					$kv = explode('=',$kvpair);
					$k = $kv[0];
					$v = urldecode($kv[1]);
					$shipArr[$k] = $v;
				}
				
				$shipArr = (object) $shipArr;
				$shipPrice = $shipArr->initial_amount;				
				$shipCarrier = $shipArr->group_name;
				
				if(!empty($newArr->shipping_method_name))
				{
		
					if($shipPrice && $shipCarrier)
					{
						$customerObj->shipCarrier = $shipCarrier;
						$customerObj->baseShipping = $shipPrice;
					}
					else
					{	
						$customerObj->shipCarrier = NULL;
						$customerObj->baseShipping = '0.00';
					}
				}
				
				
				$customerObj->clientCustomerId = $newArr->customer_id;
				$customerObj->firstName = $newArr->first_name;
				$customerObj->lastName = $newArr->last_name;
				$customerObj->address1 = $newArr->billing_street_address;
				$customerObj->address2 = $newArr->billing_street_address2;
				$customerObj->city = $newArr->billing_city;
				$customerObj->state = $newArr->billing_state;
				$customerObj->postalCode = $newArr->billing_postcode;
				$customerObj->country = $newArr->billing_country;
				$customerObj->shipFirstName = $newArr->shipping_first_name;
				$customerObj->shipLastName = $newArr->shipping_last_name;
				$customerObj->shipAddress1 = $newArr->shipping_street_address;
				$customerObj->shipAddress2 = $newArr->shipping_street_address2;
				$customerObj->shipCity = $newArr->shipping_city;
				$customerObj->shipState = $newArr->shipping_state;
				$customerObj->shipPostalCode = $newArr->shipping_postcode;
				$customerObj->shipCountry = $newArr->shipping_country;
				$customerObj->phoneNumber = $newArr->customers_telephone;
				$customerObj->emailAddress = $newArr->email_address;
				$customerObj->cardType = $newArr->cc_type;
				$customerObj->cardNumber = $newArr->cc_number;
				$customerObj->ipAddress = $newArr->ip_address;
				
				var_dump($customerObj->cardType);
				
				$customerObj->update();

				if(!empty($orderObj->orderStatus))
				{			
					if($newArr->order_status == '1')
						$newArr->order_status = 'CANCELLED';
						
					elseif($newArr->order_status == '2' || $newArr->order_status == '8')
						$newArr->order_status = 'COMPLETE';
					
					elseif($newArr->order_status == '6')
						$newArr->order_status = 'REFUNDED';
					
					elseif($newArr->order_status == '7')
						$newArr->order_status = 'DECLINED';
						
					if($newArr->order_status != $orderObj->orderStatus)
					{
						$orderObj->orderStatus = $newArr->order_status;
						
						if($orderObj->orderStatus == 'REFUNDED')
							$orderObj->items[0]->refundRemaining = '0.00';	
					}
				}
							
				$orderObj->customerId = $orderObj->customerId;
				$orderObj->clientCustomerId = $newArr->customer_id;
				$orderObj->orderStatus = $newArr->order_status;
				$orderObj->firstName = $newArr->first_name;
				$orderObj->lastName = $newArr->last_name;
				$orderObj->address1 = $newArr->billing_street_address;
				$orderObj->address2 = $newArr->billing_street_address2;
				$orderObj->city = $newArr->billing_city;
				$orderObj->state = $newArr->billing_state;
				$orderObj->postalCode = $newArr->billing_postcode;
				$orderObj->country = $newArr->billing_country;
				$orderObj->shipFirstName = $newArr->shipping_first_name;
				$orderObj->shipLastName = $newArr->shipping_last_name;
				$orderObj->shipAddress1 = $newArr->shipping_street_address;
				$orderObj->shipAddress2 = $newArr->shipping_street_address2;
				$orderObj->shipCity = $newArr->shipping_city;
				$orderObj->shipState = $newArr->shipping_state;
				$orderObj->shipPostalCode = $newArr->shipping_postcode;
				$orderObj->shipCountry = $newArr->shipping_country;
				$orderObj->phoneNumber = $newArr->customers_telephone;
				$orderObj->emailAddress = $newArr->email_address;
				$orderObj->cardType = $newArr->cc_type;
				$orderObj->cardNumber = $newArr->cc_number;
				$orderObj->ipAddress = $newArr->ip_address;
				$orderObj->clientOrderId = $newArr->orderId;
				$orderObj->dateCreated = $newArr->time_stamp;
				$orderObj->price = $newArr->{'products[0][price]'};
				$orderObj->salesTax = $newArr->order_sales_tax_amount;
				$orderObj->basePrice = $newArr->{'products[0][price]'};
				$orderObj->totalAmount = $newArr->order_total;
				$orderObj->orderValue = $newArr->order_total;
				
				$orderObj->update();
				
				$args->orderId = $orderObj->orderId;
				$fulfillmentObj = Fulfillment::fetch($args);

				if(!empty($fulfillmentObj))
				{
					
					if($newArr->tracking_number != $fulfillmentObj->trackingNumber)
					{
						$fulfillmentObj->trackingNumber = $newArr->tracking_number;
						$fulfillmentObj->status = 'SHIPPED';
						$fulfillmentObj->shipCarrier = $shipCarrier;	
						$fulfillmentObj->dateShipped = $newArr->shipping_date;
					}
					elseif(!empty($newArr->rma_number))
					{
						$fulfillmentObj->status = 'RETURNED';
						$fulfillmentObj->rmaNumber = $newArr->rma_number;
					}
					else
					{
						$fulfillmentObj->trackingNumber = $newArr->tracking_number;
						$fulfillmentObj->status = 'PENDING';
						$fulfillmentObj->shipCarrier = NULL;
						$fulfillmentObj->dateShipped = NULL;
					}
					
					$fulfillmentObj->update();
					
					$args->fulfillmentId = $fulfillmentObj->fulfillmentId;
					$fulfillmentItemObj = FulfillmentItem::fetch($args);
					
					$fulfillmentItemObj->status = $fulfillmentObj->rmaNumber;
					
					$fulfillmentItemObj->update();
											
				}
				
				for($i = 0; $i < 50; $i++)
				{
					
					$continueLoop = false;
					if(isset($newArr->{"employeeNotes[$i]"}))
					{
						
						$args->customerId = $orderObj->customerId;
						$args->externalNoteId = $orderObj->orderId.'employee'."$i";
						
						$customerHistoryObj = CustomerHistory::fetch($args);
						
						if(empty($customerHistoryObj))
						{
							
							$customerHistory = new CustomerHistory;
							$customerHistory->customerId = $orderObj->customerId;
							$customerHistory->externalNoteId = $orderObj->orderId.'employee'."$i";
							
							$employeeHyphen = strpos($newArr->{"employeeNotes[$i]"},'-');
							$employeeDate = substr($newArr->{"employeeNotes[$i]"},0,$employeeHyphen-1);
							$employeeHyphenPos = strpos($newArr->{"employeeNotes[$i]"},'-');
							$employeeLastHyphenPos = substr($newArr->{"employeeNotes[$i]"},$employeeHyphenPos+2);
							$employeeLastHyphen = strpos($employeeLastHyphenPos,'-');
							$employeeNote = substr($employeeLastHyphenPos,$employeeLastHyphen+2);

							$customerHistory->dateCreated = new DateTime($employeeDate);
							
							$message = substr($employeeNote,0);
							$message = substr($message,-1) != '.' ? $message.'.' : $message;
							
							$customerHistory->message = $message;
							$customerHistory->create();
						}
						else
						{
						
							$employeeHyphenPos = strpos($newArr->{"employeeNotes[$i]"},'-');
							$employeeLastHyphenPos = substr($newArr->{"employeeNotes[$i]"},$employeeHyphenPos+2);
							$employeeLastHyphen = strpos($employeeLastHyphenPos,'-');
							$employeeNote = substr($employeeLastHyphenPos,$employeeLastHyphen+2);
							
							$message = substr($employeeNote,0);
							$message = substr($message,-1) != '.' ? $message.'.' : $message;
												
							if($customerHistoryObj->message == $message)
							{
								$customerHistoryObj->message = $message; 
								$customerHistoryObj->update();	
							}
						}
						$continueLoop = true;					
					}
					
					if(isset($newArr->{"systemNotes[$i]"}))
					{
						
						$args->customerId = $orderObj->customerId;
						$args->externalNoteId = $orderObj->orderId.'system'."$i";
						
						$customerHistoryObj = CustomerHistory::fetch($args);
						
						if(empty($customerHistoryObj))
						{
							
							$customerHistory = new CustomerHistory;
							$customerHistory->customerId = $orderObj->customerId;
							$customerHistory->externalNoteId = $orderObj->orderId.'system'."$i";
							
							$systemHyphen = strpos($newArr->{"systemNotes[$i]"},'-');
							$systemDate = substr($newArr->{"systemNotes[$i]"},0,$systemHyphen-1);
							$systemHyphenPos = strpos($newArr->{"systemNotes[$i]"},'-');
							$systemLastHyphenPos = substr($newArr->{"systemNotes[$i]"},$systemHyphenPos+2);
							$systemLastHyphen = strpos($systemLastHyphenPos,'-');
							$systemNote = substr($systemLastHyphenPos,$systemLastHyphen+2);
				
							$customerHistory->dateCreated = new DateTime($systemDate);
							
							$message = substr($systemNote,0);
							$message = substr($message,-1) != '.' ? $message.'.' : $message;
							
							$customerHistory->message = $message;
							$customerHistory->create();
						}
						else
						{
						
							$systemHyphenPos = strpos($newArr->{"systemNotes[$i]"},'-');
							$systemLastHyphenPos = substr($newArr->{"systemNotes[$i]"},$systemHyphenPos+2);
							$systemLastHyphen = strpos($systemLastHyphenPos,'-');
							$systemNote = substr($systemLastHyphenPos,$systemLastHyphen+2);
			
							$message = substr($systemNote,0);
							$message = substr($message,-1) != '.' ? $message.'.' : $message;
							
							if($customerHistoryObj->message == $message)
							{
								$customerHistoryObj->message = $message; 
								$customerHistoryObj->update();	
							}
						}
						$continueLoop = true;			
					}
					
					if(!$continueLoop)
					break;
						
				}
				
				
			}
			else
			{
				continue;	
			}
		
		}
		

	}
	
	function updateLimelightOrder($customer)
	{
		
		$orderId = $customer->clientOrderId;
		$address1 = !empty($customer->address1) ? $customer->address1 : NULL;
		$firstName = !empty($customer->firstName) ? $customer->firstName : NULL;
		$lastName = !empty($customer->lastName) ? $customer->lastName : NULL;
		$email = !empty($customer->emailAddress) ? $customer->emailAddress : NULL;
		$phone = !empty($customer->phoneNumber) ? $customer->phoneNumddress1 : NULL;
		$address2 = !empty($customer->address2) ? $customer->address2 : NULL;
		$city = !empty($customer->city) ? $customer->city : NULL;
		$state = !empty($customer->state) ? $customer->state : NULL;
		$zip = !empty($customer->postalCode) ? $customer->postalCode : NULL;
		$country = !empty($customer->country) ? $customer->country : NULL;
		$shipAddress1 = !empty($customer->shipAddress1) ? $customer->shipAddress1 : NULL;
		$shipAddress2 = !empty($customer->shipAddress2) ? $customer->shipAddress2 : NULL;
		$shipCity = !empty($customer->shipCity) ? $customer->shipCity : NULL;
		$shipState = !empty($customer->shipState) ? $customer->shipState : NULL;
		$shipZip = !empty($customer->shipPostalCode) ? $customer->shipPostalCode : NULL;
		$shipCountry = !empty($customer->shipCountry) ? $customer->shipCountry : NULL;
		
		$actions = array(
			'first_name',
			'last_name',
			'email',
			'phone',
			'shipping_address1',
			'shipping_address2',
			'shipping_city',
			'shipping_zip',
			'shipping_state',
			'shipping_country',
			'billing_address1',
			'billing_address2',
			'billing_city',
			'billing_zip',
			'billing_state',
			'billing_country');
				
		$values = array(
			"$firstName",
			"$lastName",
			"$email",
			"$phone",
			"$shipAddress1",
			"$shipAddress2",
			"$shipCity",
			"$shipZip",
			"$shipState",
			"$shipCountry",
			"$address1",
			"$address2",
			"$city",
			"$zip",
			"$state",
			"$country");	
				
		$orderIds = implode(",",array_fill(0,count($values),$orderId));
		
		$params = (object) array();
							
		$params->username = $this->username;
		$params->password = $this->password;
		$params->method = 'order_update';
		$params->order_ids = $orderIds;
		$params->sync_all = '1';
		$params->actions = implode(",",$actions);
		$params->values = implode(",",$values);
		
		$url = 'https://'.$this->domain.'/admin/membership.php';
		$headers = array();
		$headers[] = 'Content-Type: application/x-www-form-urlencoded';
		
		$request = new HttpRequest($url);
		$request->headers = $headers;
		$request->method = 'POST';
		$request->body = http_build_query($params);

		$response = HttpClient::sendRequest($request);
		$this->logRequest($request);
		
		$body = explode(',',substr($response->body,'14'));
		
		$code = array('343','100');
		
		$arrDiff = array_diff($code,$body);

		if(count($arrDiff) == '2')
			throw new ValidationException('Not able to Update Customer in Limelight CRM.');
		else
			return true;
	}
		
	function cancelOrder($args,$order)
	{
		
		$orderId = $order->clientOrderId;
		
		$params = (object) array();
		
		$params->username = $this->username;
		$params->password = $this->password;
		$params->method = 'order_update_recurring';
		$params->order_id = $orderId;
		$params->status = 'stop';
				
		$url = 'https://'.$this->domain.'/admin/membership.php';
		$headers = array();
		$headers[] = 'Content-Type: application/x-www-form-urlencoded';
		
		$request = new HttpRequest($url);
		$request->headers = $headers;
		$request->method = 'POST';
		$request->body = http_build_query($params);
		
		$response = HttpClient::sendRequest($request);
		$this->logRequest($request);
		
		if($args->afterNextBill == '1')
		{
			
			$params = (object) array();
			
			$params->username = $this->username;
			$params->password = $this->password;
			$params->method = 'order_update';
			$params->order_ids = $orderId;
			$params->actions = 'stop_recurring_next_success';
			$params->values = '1';
			
			$url = 'https://'.$this->domain.'/admin/membership.php';
			$headers = array();
			$headers[] = 'Content-Type: application/x-www-form-urlencoded';
			
			$request = new HttpRequest($url);
			$request->headers = $headers;
			$request->method = 'POST';
			$request->body = http_build_query($params);
			
			$response = HttpClient::sendRequest($request);
			$this->logRequest($request);
			
		}
		
		if($args->fullRefund == '1')
		{
			
			$params = (object) array();
			
			$params->username = $this->username;
			$params->password = $this->password;
			$params->method = 'order_void';
			$params->order_id = $orderId;
			
			$url = 'https://www.orderupdating.com/admin/membership.php';
			$headers = array();
			$headers[] = 'Content-Type: application/x-www-form-urlencoded';
			
			$request = new HttpRequest($url);
			$request->headers = $headers;
			$request->method = 'POST';
			$request->body = http_build_query($params);
			
			$response = HttpClient::sendRequest($request);
			$this->logRequest($request);
			
		}
		
		$body = explode(',',substr($response->body,'14'));
		
		if($body[0] == '100')
			return true;
		else
			throw new ValiadationException('Not able to Cancel Order in Limelight CRM.');
				
	}
	
	function refundOrder($order,$refundAmount,$fullRefund)
	{
		
		$orderId = $order->clientOrderId;
		
		if($fullRefund == true)
		{
		
			$params = (object) array();
			
			$params->username = $this->username;
			$params->password = $this->password;
			$params->method = 'order_calculate_refund';
			$params->order_id = $orderId;
			
			$url = 'https://'.$this->domain.'/admin/membership.php';
			$headers = array();
			$headers[] = 'Content-Type: application/x-www-form-urlencoded';
			
			$request = new HttpRequest($url);
			$request->headers = $headers;
			$request->method = 'POST';
			$request->body = http_build_query($params);
			
			$response = HttpClient::sendRequest($request);
			$this->logRequest($request);
			
			$body = explode('&',$response->body);
			
			$newArr = array();
			foreach($body as $kvpair)
			{
				$kv = explode('=',$kvpair);
				$newArr[$kv[0]] = $kv[1];
			}
			
			$newArr = (object) $newArr;
			$refundAmount = $newArr->amount;
				
			$params = (object) array();
			
			$params->username = $this->username;
			$params->password = $this->password;
			$params->method = 'order_refund';
			$params->order_id = $orderId;
			$params->amount = $refundAmount;
			
			$url = 'https://'.$this->domain.'/admin/membership.php';
			$headers = array();
			$headers[] = 'Content-Type: application/x-www-form-urlencoded';
			
			$request = new HttpRequest($url);
			$request->headers = $headers;
			$request->method = 'POST';
			$request->body = http_build_query($params);
			
			$response = HttpClient::sendRequest($request);
			$this->logRequest($request);
			
		}
		
		if(!empty($refundAmount))
		{
			
			$params = (object) array();
			
			$params->username = $this->username;
			$params->password = $this->password;
			$params->method = 'order_refund';
			$params->order_id = $orderId;
			$params->amount = $refundAmount;
			
			$url = 'https://'.$this->domain.'/admin/membership.php';
			$headers = array();
			$headers[] = 'Content-Type: application/x-www-form-urlencoded';
			
			$request = new HttpRequest($url);
			$request->headers = $headers;
			$request->method = 'POST';
			$request->body = http_build_query($params);
			
			$response = HttpClient::sendRequest($request);
			$this->logRequest($request);
				
		}
		
		$body = explode(',',substr($response->body,'14'));
		
		if($body[0] == '100')
			return true;
		else
			throw new ValiadationException('Not able to Refund Order in Limelight CRM.');
			
	}
	
	function createLeads()
	{
		
		$app = Application::getInstance();
		$server = $app->getClientServer();
		
		$params = (object) array();
		
		$params->username = $this->username;
		$params->password = $this->password;
		$params->method = 'prospect_find';
		$params->campaign_id = 'all';
		$params->start_date = '01/01/1990';
		$params->end_date = date('m/d/Y');
		$params->search_type = 'all';
		
		$url = 'https://'.$this->domain.'/admin/membership.php';
		$headers = array();
		$headers[] = 'Content-Type: application/x-www-form-urlencoded';
		
		$request = new HttpRequest($url);
		$request->headers = $headers;
		$request->method = 'POST';
		$request->body = http_build_query($params);
			
		$response = HttpClient::sendRequest($request);
		$this->logRequest($request);
		
		$pos = strrpos($response->body,'=');
		$body = substr($response->body,$pos+1);
		$allProspects = explode(',',$body);
		
		foreach($allProspects as $prospect)
		{
			
			$params = (object) array();
			
			$params->username = $this->username;
			$params->password = $this->password;
			$params->method = 'prospect_view';
			$params->prospect_id = $prospect;
			
			$url = 'https://'.$this->domain.'/admin/membership.php';
			$headers = array();
			$headers[] = 'Content-Type: application/x-www-form-urlencoded';
			
			$request = new HttpRequest($url);
			$request->headers = $headers;
			$request->method = 'POST';
			$request->body = http_build_query($params);
			
			$response = HttpClient::sendRequest($request);
			$this->logRequest($request);
			
			$arr = explode('&',$response->body);
			
			$prospectArr = array();
			foreach($arr as $kvpair)
			{
				$kv = explode('=',$kvpair);
				$k = $kv[0];
				$v = urldecode($kv[1]);
				$prospectArr[$k] = $v;		
			}
			
			$prospectArr = (object) $prospectArr;
			
			$customerObj = new Customer;
			$orderObj = new Order;
			
			$limelightCampaignId = $prospectArr->campaign_id;
			
			$sql = "SELECT campaignId FROM campaigns WHERE campaignId = ?";
			$campaignId = $server->fetchValue($sql,$limelightCampaignId);
			
			if(empty($limelightCampaignId))
				throw new ValidationException("Partial Order must include a campaign");
			
			if(!$campaignId)
			{
				$campaignId = $this->createCampaign($limelightCampaignId);
				$customerObj->campaignId = $campaignId;
			}
			else
			{
				$customerObj->campaignId = $campaignId;	
			}
			
			$customerObj->firstName = $prospectArr->first_name;
			$customerObj->lastName = $prospectArr->last_name;
			$customerObj->address1 = $prospectArr->address;
			$customerObj->address2 = $prospectArr->address2;
			$customerObj->city = $prospectArr->city;
			$customerObj->state = $prospectArr->state;
			$customerObj->postalCode = $prospectArr->zip;
			$customerObj->country = $prospectArr->country;
			$customerObj->shipFirstName = $prospectArr->first_name;
			$customerObj->shipLastName = $prospectArr->last_name;
			$customerObj->shipAddress1 = $prospectArr->address;
			$customerObj->shipAddress2 = $prospectArr->address2;
			$customerObj->shipCity = $prospectArr->city;
			$customerObj->shipState = $prospectArr->state;
			$customerObj->shipPostalCode = $prospectArr->zip;
			$customerObj->shipCountry = $prospectArr->country; 
			$customerObj->phoneNumber = $prospectArr->phone;
			$customerObj->emailAddress = $prospectArr->email;
			$customerObj->ipAddress = $prospectArr->ip_address;
			$customerObj->dateCreated = $prospectArr->date_created;

			$customerObj->create();
			
			$orderObj->updateValues($customerObj);
			$orderObj->orderStatus = 'PARTIAL';
			$orderObj->clientOrderId = strings::randomHex(10);
			$orderObj->externalPluginId = $this->pluginId;
			
			$orderObj->create();
		}
	}
}
