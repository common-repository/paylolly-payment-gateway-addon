var successCallbackToken = function(data) {

    var checkout_form = jQuery('form.woocommerce-checkout');

    // add a token to our hidden input field
    // console.log(data) to find the token
    checkout_form.find('#paylolly_token').val(data);
};
var successCallbackCvv = function(data) {

    var checkout_form = jQuery('form.woocommerce-checkout');

    // add a token to our hidden input field
    // console.log(data) to find the token
    checkout_form.find('#paylolly_cvv').val(data);
};
var successCallback = function(){
    var checkout_form = jQuery('form.woocommerce-checkout');
    // deactivate the tokenRequest function event
    checkout_form.off('checkout_place_order', tokenRequest);

    // submit the form now
    checkout_form.submit();

};

var errorCallback = function(data) {
    console.log(data);
};

var tokenRequest = function() {
	const newcard=jQuery("#wc-paylolly-payment-token-new").length==0||jQuery("#wc-paylolly-payment-token-new").is(':checked');
	const cardno=jQuery("#paylolly-card-number").val().replace(/\s/g,'');
	const expMMYY=jQuery("#paylolly-card-expiry").val().replace(/\s/g,'');
	const cvv=jQuery("#paylolly-card-cvc").val().replace(/\s/g,'');
	const expYYMM=expMMYY.substr(-2)+expMMYY.substr(0,2);
	//window.alert(cardno);
	//window.alert(expMMYY);
	//debugger;
	let promiseArray=[];
	if (newcard)
		promiseArray.push(
		jQuery.ajax({
			contentType: 'application/json',
			crossDomain: true,
			datatype: 'json',
			type: 'POST',
			url: paylolly_params.url + '/api/rest/v1.0/TokenJS/TokenizeCard',
			headers: {
				'accept': 'application/json',
				'MerchantSessionKey': paylolly_params.sessionKey
			},
			data: JSON.stringify({
				'cardNo': cardno,
				'expiryYYMM': expYYMM,
			}),
			success: successCallbackToken,
			fail: errorCallback
		}));
	promiseArray.push(
		jQuery.ajax({
			contentType: 'application/json',
			crossDomain: true,
			datatype: 'json',
			type: 'POST',
			url: paylolly_params.url + '/api/rest/v1.0/TokenJS/EncryptTransactionData?id='+paylolly_params.merchantId,
			headers: {
				'accept': 'application/json',
				'MerchantSessionKey': paylolly_params.sessionKey
			},
			data: JSON.stringify(cvv),
			success: successCallbackCvv,
			fail: errorCallback
		}));
	Promise.all(promiseArray).then(successCallback).catch(errorCallback);


    return false;

};

jQuery(function($) {

    var checkout_form = $('form.woocommerce-checkout');
    checkout_form.on('checkout_place_order', tokenRequest);

});
