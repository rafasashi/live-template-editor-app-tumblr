<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class LTPLE_Integrator_Tumblr extends LTPLE_Client_Integrator {
	
	public function init_app() {

		if( isset($this->parameters['key']) ){
			
			$tblr_consumer_key 		= array_search('tblr_consumer_key', $this->parameters['key']);
			$tblr_consumer_secret 	= array_search('tblr_consumer_secret', $this->parameters['key']);
			$tblr_oauth_callback 	= $this->parent->urls->apps;
			
			if( !empty($this->parameters['value'][$tblr_consumer_key]) && !empty($this->parameters['value'][$tblr_consumer_secret]) ){
			
				define('CONSUMER_KEY', 		$this->parameters['value'][$tblr_consumer_key]);
				define('CONSUMER_SECRET', 	$this->parameters['value'][$tblr_consumer_secret]);
				//define('OAUTH_CALLBACK', 	$tblr_oauth_callback);

				// init action
		
				if( $action = $this->get_current_action() ){
				
					$this->init_action($action);
				}
			}
			else{
				
				$message = '<div class="alert alert-danger">';
					
					$message .= 'Sorry, tumblr is not yet available on this platform, please contact the dev team...';
						
				$message .= '</div>';

				$this->parent->session->update_user_data('message',$message);
			}
		}
	}

	public function appConnect(){
		
		$client = new Tumblr\API\Client(CONSUMER_KEY, CONSUMER_SECRET);
		
		if( isset($_REQUEST['action']) ){
			
			$this->connection = $client->getRequestHandler();
			$this->connection->setBaseUrl('https://www.tumblr.com/');

			// start the old gal up
			$resp = $this->connection->request('POST', 'oauth/request_token', array());
			
			// get the oauth_token
			parse_str($resp->body, $this->request_token);
			
			$this->parent->session->update_user_data('app',$this->app_slug);
			$this->parent->session->update_user_data('action',$_REQUEST['action']);
			$this->parent->session->update_user_data('ref',$this->get_ref_url());
			
			$this->parent->session->update_user_data('oauth_token',$this->request_token['oauth_token']);
			$this->parent->session->update_user_data('oauth_token_secret',$this->request_token['oauth_token_secret']);			
	
			if( !empty($this->request_token['oauth_token']) ){
			
				$this->oauth_url = 'https://www.tumblr.com/oauth/authorize?oauth_token=' . $this->request_token['oauth_token'];
				
				wp_redirect($this->oauth_url);
				echo 'Redirecting tumblr oauth...';
				exit;		
			}
		}
		elseif(isset($_REQUEST['oauth_token'])){
			
			// handle connect callback
			
			$this->request_token = [];
			$this->request_token['oauth_token'] 		= $this->parent->session->get_user_data('oauth_token');
			$this->request_token['oauth_token_secret'] 	= $this->parent->session->get_user_data('oauth_token_secret');

			if(isset($_REQUEST['oauth_token']) && $this->request_token['oauth_token'] !== $_REQUEST['oauth_token']) {
				
				$this->reset_session();	
				
				// store failure message

				$message = '<div class="alert alert-danger">';
					
					$message .= 'Tumblr connection failed...';
						
				$message .= '</div>';
				
				$this->parent->session->update_user_data('message',$message);
			}
			elseif(isset($_REQUEST['oauth_verifier'])){
				
				// set temporary oauth_token
				
				$client->setToken($this->request_token['oauth_token'],$this->request_token['oauth_token_secret']);
				
				// get new Request Handler
				
				$this->connection = $client->getRequestHandler();
				$this->connection->setBaseUrl('https://www.tumblr.com/');			
				
				//get the long lived access_token that authorized to act as the user
				
				$resp = $this->connection->request('POST', 'oauth/access_token', array('oauth_verifier' => $_REQUEST['oauth_verifier']));
				parse_str($resp->body, $this->access_token);

				//flush session
				
				$this->reset_session();

				//store access_token in session					

				$this->parent->session->update_user_data('access_token',$this->access_token);
				
				// set access oauth_token
				$client = new Tumblr\API\Client(CONSUMER_KEY, CONSUMER_SECRET, $this->access_token['oauth_token'], $this->access_token['oauth_token_secret']);
				
				// get user info
				
				$info = $client->getUserInfo();

				if(!empty($info->user->blogs)){
					
					// append user name
					
					$this->access_token['user_name'] = $info->user->name;
					
					// get main account token
					
					foreach($info->user->blogs as $blog){

						if( $blog->admin === true ){
							
							// store access_token in database		
							
							$app_title = wp_strip_all_tags( 'tumblr - ' . $blog->name );
							
							$app_item = get_page_by_title( $app_title, OBJECT, 'user-app' );
							
							if( empty($app_item) ){
								
								// create app item
								
								$app_id = wp_insert_post(array(
								
									'post_title'   	 	=> $app_title,
									'post_status'   	=> 'publish',
									'post_type'  	 	=> 'user-app',
									'post_author'   	=> $this->parent->user->ID
								));
								
								wp_set_object_terms( $app_id, $this->term->term_id, 'app-type' );
								
								// hook connected app
								
								do_action( 'ltple_thumblr_account_connected');
								
								$this->parent->apps->newAppConnected();
							}
							else{
								
								$app_id = $app_item->ID;
							}
								
							// update app item
								
							update_post_meta( $app_id, 'appData', json_encode($this->access_token,JSON_PRETTY_PRINT));							
						}							
					}
				}
				
				// store success message

				$message = '<div class="alert alert-success">';
					
					$message .= 'Congratulations, you have successfully connected a Tumblr account!';
						
				$message .= '</div>';

				$this->parent->session->update_user_data('message',$message);
			}
			else{
					
				//flush session
				
				$this->reset_session();
			}
				
			if( $redirect_url = $this->parent->session->get_user_data('ref') ){
				
				wp_redirect($redirect_url);
				echo 'Redirecting tumblr callback...';
				exit;	
			}
		}
	}
	
	public function appImportImg(){
		
		if(!empty($_REQUEST['id'])){
		
			if( $this->app = $this->parent->apps->getAppData( $_REQUEST['id'], $this->parent->user->ID ) ){
				
				$client = new Tumblr\API\Client(CONSUMER_KEY, CONSUMER_SECRET, $this->app->oauth_token, $this->app->oauth_token_secret);
										
				$blog = $client->getBlogPosts($this->app->user_name);
				
				$urls = [];
				
				if(!empty($blog->posts)){
					
					foreach($blog->posts as $item){
						
						if(!empty($item->photos)){
							
							foreach($item->photos as $photo){
								
								$img_title	= basename($photo->original_size->url);
								$img_url	= $photo->original_size->url;
								
								if(!get_page_by_title( $img_title, OBJECT, 'user-image' )){
									
									if($image_id = wp_insert_post(array(
								
										'post_author' 	=> $this->parent->user->ID,
										'post_title' 	=> $img_title,
										'post_content' 	=> $img_url,
										'post_type' 	=> 'user-image',
										'post_status' 	=> 'publish'
									))){
										
										wp_set_object_terms( $image_id, $this->term->term_id, 'app-type' );
									}
								}						
							}
						}
					}
				}
			}
		}
	}
	
	public function reset_session(){
		
		$this->parent->session->update_user_data('access_token','');		
		$this->parent->session->update_user_data('oauth_token','');
		$this->parent->session->update_user_data('oauth_token_secret','');
		$this->parent->session->update_user_data('ref',$this->get_ref_url());		
		
		return true;
	}
}
