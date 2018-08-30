var C2EcomCheckout = function() {
    "use strict";
    var Environment = {};

    function initialize() {
        var script = document.getElementById("c2CheckoutScript");
        Environment.mode = script.getAttribute("data-c2-mode");
        Environment.apiKey = script.getAttribute("data-c2-partnerApiKey");
        Environment.interestFreeDaysMerchant = script.getAttribute("data-c2-interestFreeDaysMerchant") || 0;
        Environment.amount = script.getAttribute("data-c2-amount");
        Environment.checkoutCallback = script.getAttribute("data-c2-checkoutCallback") && script.getAttribute("data-c2-checkoutCallback") === "true";
        if (Environment.amount <= 0) {
            console.error("provided amount of " + Environment.amount + " is invalid")
        }
        Environment.c2PrefillData = {
            email: script.getAttribute("data-c2-email"),
            given: script.getAttribute("data-c2-given"),
            family: script.getAttribute("data-c2-family"),
            birthdate: script.getAttribute("data-c2-birthdate"),
            country: script.getAttribute("data-c2-country"),
            city: script.getAttribute("data-c2-city"),
            zip: script.getAttribute("data-c2-zip"),
            addressline: script.getAttribute("data-c2-addressline"),
            phone: script.getAttribute("data-c2-phone"),
            iban: script.getAttribute("data-c2-iban"),
            checkoutCallback: Environment.checkoutCallback
        };
        Environment.sessionCookieName = "c2EcomId";
        Environment.purchaseInfoRequest = "purchaseInfo";
        Environment.htmlSnipplet = "c2_ecom_checkout";
        Environment.checkoutSelector = "cashpresso-checkout";
        Environment.checkoutToken = "cashpressoToken";
        Environment.responseErrorCount = 0;
        Environment.month = {
            en: {
                months: ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"]
            },
            de: {
                months: ["Jänner", "Februar", "März", "April", "Mai", "Juni", "Juli", "August", "September", "Oktober", "November", "Dezember"]
            }
        };
        if (Environment.mode === "test") {
            Environment.baseUrl = "https://test.cashpresso.com/frontend/ecommerce/v2/checkout/";
            Environment.wizardUrl = "https://test.cashpresso.com/frontend/ecommerce/v2/overlay-wizard/index.html#/init/" + Environment.mode + "/" + Environment.apiKey;
            Environment.backendBaseUrl = "https://test.cashpresso.com/rest/backend/ecommerce/v2"
        } else if (Environment.mode === "local") {
            Environment.baseUrl = "http://localhost:8082/frontend/ecommerce/v2/checkout/";
            Environment.wizardUrl = "http://localhost:8082/frontend/ecommerce/v2/overlay-wizard/index.html#/init/" + Environment.mode + "/" + Environment.apiKey;
            Environment.backendBaseUrl = "http://localhost:8080/rest/backend/ecommerce/v2"
        } else {
            Environment.baseUrl = "https://my.cashpresso.com/ecommerce/v2/checkout/";
            Environment.wizardUrl = "https://my.cashpresso.com/ecommerce/v2/overlay-wizard/index.html#/init/" + Environment.mode + "/" + Environment.apiKey;
            Environment.backendBaseUrl = "https://backend.cashpresso.com/rest/backend/ecommerce/v2"
        }
        Environment.locale = script.getAttribute("data-c2-locale") || document.documentElement.lang || navigator.language || navigator.userLanguage || "de";
        if (Environment.locale === "de") {
            Environment.thousandSeparator = ".";
            Environment.decimalSeparator = ","
        } else {
            Environment.thousandSeparator = ",";
            Environment.decimalSeparator = "."
        }
        loadCSS();
        initEcomIdAndPurchaseInfo()
    }

    function initEcomIdAndPurchaseInfo() {

        	console.log( "init" );
        Environment.ecomSessionId = getSessionCookie();
        console.log( getSessionCookie() );
        console.log( Environment.purchaseInfoRequest );
        console.log( getPurchaseInfoRequest() );
        console.log( setResponseInfo );

        httpPostAsync(Environment.purchaseInfoRequest, getPurchaseInfoRequest(), setResponseInfo)
    }

    function setResponseInfo(text) {
        var response = JSON.parse(text);
        Environment.purchaseInfo = response;
        if (hasError()) {
            return
        }
        if (response && response.c2EcomId) {
            createSessionCookie(Environment.sessionCookieName, response.c2EcomId, 30)
        }
        initCheckoutLabel()
    }

    function initCheckoutLabel() {
        if (Environment.purchaseInfo.state) {
            insertSnipplet(Environment.purchaseInfo.state)
        }
    }

    function hasError() {
        if (Environment.purchaseInfo && Environment.purchaseInfo.success) {
            Environment.responseErrorCount = 0;
            var cookie = getSessionCookie();
            if ((!cookie || cookie.length === 0) && Environment.purchaseInfo.c2EcomId) {
                createSessionCookie(Environment.sessionCookieName, Environment.purchaseInfo.c2EcomId, 30)
            }
            return false
        }
        if (Environment.purchaseInfo.error && Environment.purchaseInfo.error.type === "INVALID_INPUT" && Environment.purchaseInfo.error.description.indexOf("c2EcomId") >= 0 && Environment.responseErrorCount < 2) {
            Environment.ecomSessionId = null;
            deleteCookie(Environment.sessionCookieName);
            httpPostAsync(Environment.purchaseInfoRequest, getPurchaseInfoRequest(), setResponseInfo)
        } else if (Environment.purchaseInfo.error && Environment.purchaseInfo.error.type === "LIMIT_EXCEEDED") {
            insertSnipplet("ERR_LIMIT");
            return
        } else {
            insertSnipplet("ERR");
            return
        }
        Environment.responseErrorCount++;
        return true
    }

    function insertSnipplet(state) {
        var checkoutEle = document.getElementById(Environment.checkoutSelector);
        if (!checkoutEle) {
            updateAvailabilityBannerOnly(state);
            return
        }
        if (Environment.purchaseInfo.pendingPurchase) {
            state = "NEW"
        }
        var url = Environment.baseUrl + Environment.htmlSnipplet + "_" + state + ".html";
        httpRequestAsync(url, "GET", null, function(text) {
            checkoutEle.classList.add(Environment.locale === "de" ? "c2-de" : "c2-en");
            checkoutEle.innerHTML = text;
            initInfo(state)
        })
    }

    function updateAvailabilityBannerOnly(state) {
        if (state === "ACTIVE" || state === "OK") {
            showAvailabilityBanner()
        } else {
            hideAvailabilityBanner()
        }
    }

    function initInfo(state) {
        if (state === "ACTIVE" && !Environment.purchaseInfo.pendingPurchase) {
            setAmount(Environment.purchaseInfo.planUpdate.totalAmount, "c2-total-row", false);
            setAmount(Environment.purchaseInfo.planUpdate.additionalInterest, "c2-interest-row", false);
            setAmount(Environment.purchaseInfo.amount - Environment.purchaseInfo.prePayment, "c2-financing-amount-row", false);
            setAmount(Environment.purchaseInfo.prePayment, "c2-prepayment-row", true);
            setAdditionalPayback();
            showAvailabilityBanner()
        } else if (state === "OK" && !Environment.purchaseInfo.pendingPurchase) {
            setAmount(Environment.purchaseInfo.prePayment, "c2-prepayment-row", true);
            setAmount(Environment.purchaseInfo.currentPlan.totalAmount, "c2-total-row", false);
            setAmount(Environment.purchaseInfo.currentPlan.lastInstalment, "c2-last-instalment-row", true);
            setInstalments();
            showAvailabilityBanner()
        } else {
            hideAvailabilityBanner()
        }
        setToken()
    }

    function setToken() {
        var token = document.getElementById("cashpressoToken");
        if ((Environment.purchaseInfo.state === "ACTIVE" || Environment.purchaseInfo.state === "OK") && !Environment.purchaseInfo.pendingPurchase) {
            token.value = Environment.ecomSessionId
        } else {
            token.value = ""
        }
    }

    function showAvailabilityBanner() {
        var availabilityBanner = document.getElementById("cashpresso-availability-banner");
        if (!availabilityBanner) {
            return
        }
        availabilityBanner.classList.add(Environment.locale === "de" ? "c2-de" : "c2-en");
        if (availabilityBanner.getElementsByClassName("c2-availability-label")[0]) {
            availabilityBanner.style.display = "block"
        }
        var url = Environment.baseUrl + "c2_ecom_checkout_availability.html";
        httpRequestAsync(url, "GET", null, function(text) {
            availabilityBanner.style.display = "block";
            availabilityBanner.innerHTML = text
        })
    }

    function hideAvailabilityBanner() {
        var availabilityBanner = document.getElementById("cashpresso-availability-banner");
        if (!availabilityBanner) {
            return
        }
        availabilityBanner.style.display = "none"
    }

    function setAmount(amount, className, optional) {
        var amountEle = document.getElementById(Environment.checkoutSelector).getElementsByClassName(className)[0];
        if (!amountEle) {
            return
        }
        if (optional && !amount) {
            amountEle.style.display = "none"
        } else {
            var formatted = amount._formatMoney(2, Environment.decimalSeparator, Environment.thousandSeparator) + " €";
            amountEle.getElementsByClassName("c2-pull-right")[0].innerHTML = formatted
        }
    }

    function setInstalments() {
        var duration = Environment.purchaseInfo.currentPlan.lastInstalment ? Environment.purchaseInfo.currentPlan.duration - 1 : Environment.purchaseInfo.currentPlan.duration;
        var amount = Environment.purchaseInfo.currentPlan.instalmentAmount;
        var instalmentsEle = document.getElementById(Environment.checkoutSelector).getElementsByClassName("c2-instalments-row")[0];
        if (!instalmentsEle) {
            return
        }
        var formatted = amount._formatMoney(2, Environment.decimalSeparator, Environment.thousandSeparator);
        var result = duration + " x " + formatted + " €";
        instalmentsEle.getElementsByClassName("c2-pull-right")[0].innerHTML = result;
        var firstDue = new Date(Environment.purchaseInfo.currentPlan.instalments[0].due);
        var month = Environment.month[Environment.locale].months[firstDue.getMonth()];
        setLocaleString(instalmentsEle.getElementsByClassName("c2-pull-left")[0], "Raten ab " + month, "Instalments from " + month)
    }

    function setAdditionalPayback() {
        var currentPayback = Environment.purchaseInfo.planUpdate.currentPayback || 0;
        var additionalPayback = Environment.purchaseInfo.planUpdate.additionalPayback;
        var currentFormatted = currentPayback._formatMoney(2, Environment.decimalSeparator, Environment.thousandSeparator);
        var additionalFormatted = additionalPayback._formatMoney(2, Environment.decimalSeparator, Environment.thousandSeparator);
        var additionalEle = document.getElementById(Environment.checkoutSelector).getElementsByClassName("c2-current-instalment-row")[0];
        if (!additionalEle) {
            return
        }
        setLocaleString(additionalEle, "Aktuelle Rate von " + currentFormatted + " € erhöhen um <br>plus <strong>" + additionalFormatted + " €</strong>", "Raise current payback of " + currentFormatted + " € by <br>plus <strong>" + additionalFormatted + " €</strong>")
    }

    function setLocaleString(element, deString, enString) {
        if (Environment.locale === "de") {
            element.innerHTML = deString
        } else {
            element.innerHTML = enString
        }
    }

    function refreshAmount(amount) {
        if (amount <= 0) {
            console.error("provided amount of " + amount + " is invalid")
        }
        if (amount && amount !== Environment.amount) {
            Environment.amount = amount
        }
        console.log( amount );
        initEcomIdAndPurchaseInfo()
    }

    function startWizard(event) {
        if (!Environment.ecomSessionId) {
            Environment.ecomSessionId = getSessionCookie()
        }
        var frame = document.getElementById("c2WizardFrame");
        var purchaseAmount = Environment.amount;
        if (!frame) {
            var newFrame = document.createElement("iframe");
            newFrame.id = "c2WizardFrame";
            newFrame.height = "100%";
            newFrame.width = "100%";
            newFrame.style.position = "fixed";
            newFrame.style.top = "0px";
            newFrame.style.left = "0px";
            newFrame.style.zIndex = "9999999999";
            newFrame.src = Environment.wizardUrl + "/" + Environment.ecomSessionId + "/" + purchaseAmount + "/" + Environment.interestFreeDaysMerchant + "/" + true;
            newFrame.allowtransparency = "true";
            document.body.appendChild(newFrame)
        } else {
            frame.contentWindow.postMessage({
                function: "c2UpdateSession",
                c2PurchaseAmount: purchaseAmount,
                c2EcomId: Environment.ecomSessionId
            }, "*");
            frame.style.display = "block"
        }
        disableScroll()
    }

    function getPurchaseInfoRequest() {
        return {
            partnerApiKey: Environment.apiKey,
            c2EcomId: Environment.ecomSessionId,
            withOptions: false,
            interestFreeDaysMerchant: Environment.interestFreeDaysMerchant,
            amount: Environment.amount
        }
    }

    function handleMessage(event) {
        var data = event.data;
        if (data.function === "c2UpdateEcomId") {
            createSessionCookie(Environment.sessionCookieName, data.c2EcomId, 30)
        }
        if (data.function === "c2RequestPrefillData") {
            sendOptionalDataAttributes()
        }
        if (data.function === "c2CloseWizard") {
            c2CloseWizard()
        }
        if (data.purchased === true) {
            document.getElementById("cashpressoToken").value = Environment.ecomSessionId;
            if (Environment.checkoutCallback && window.c2Checkout) {
                window.c2Checkout()
            }
        }
    }

    function c2CloseWizard() {
        enableScroll();
        initEcomIdAndPurchaseInfo();
        document.getElementById("c2WizardFrame").style.display = "none"
    }

    function disableScroll() {
        var body = document.body;
        var html = document.documentElement;
        if (!html.classList.contains("c2-noscroll") && body.scrollHeight > html.clientHeight) {
            var scrollTop = html.scrollTop ? html.scrollTop : body.scrollTop;
            html.classList.add("c2-noscroll");
            html.style.top = "" + -scrollTop + "px"
        }
    }

    function enableScroll() {
        var body = document.body;
        var html = document.documentElement;
        if (html.classList.contains("c2-noscroll")) {
            var scrollTop = parseInt(html.style.top);
            html.classList.remove("c2-noscroll");
            html.style.top = null;
            html.scrollTop = -scrollTop
        }
    }

    function refreshOptionalDataAttributes(options) {
        if (!Environment.c2PrefillData) {
            console.log("please do not call refreshOptionalData before init has run (after DOM has loaded)");
            return
        }
        Environment.c2PrefillData.email = options.email ? options.email : Environment.c2PrefillData.email;
        Environment.c2PrefillData.given = options.given ? options.given : Environment.c2PrefillData.given;
        Environment.c2PrefillData.family = options.family ? options.family : Environment.c2PrefillData.family;
        Environment.c2PrefillData.birthdate = options.birthdate ? options.birthdate : Environment.c2PrefillData.birthdate;
        Environment.c2PrefillData.country = options.country ? options.country : Environment.c2PrefillData.country;
        Environment.c2PrefillData.city = options.city ? options.city : Environment.c2PrefillData.city;
        Environment.c2PrefillData.zip = options.zip ? options.zip : Environment.c2PrefillData.zip;
        Environment.c2PrefillData.addressline = options.addressline ? options.addressline : Environment.c2PrefillData.addressline;
        Environment.c2PrefillData.phone = options.phone ? options.phone : Environment.c2PrefillData.phone;
        Environment.c2PrefillData.iban = options.iban ? options.iban : Environment.c2PrefillData.iban;
        Environment.c2PrefillData.checkoutCallback = Environment.checkoutCallback;
        sendOptionalDataAttributes()
    }

    function sendOptionalDataAttributes() {
        var frame = document.getElementById("c2WizardFrame");
        if (frame) {
            frame.contentWindow.postMessage({
                function: "c2SetPrefillData",
                c2PrefillData: Environment.c2PrefillData
            }, "*")
        }
    }

    function httpPostAsync(endpoint, data, callback) {
        var url = Environment.backendBaseUrl + "/" + endpoint;
        httpRequestAsync(url, "POST", data, callback)
    }

    function httpRequestAsync(url, method, data, callback) {
        var xhr = new XMLHttpRequest;
        xhr.open(method, url, true);
        xhr.setRequestHeader("Content-Type", "application/json");
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4 && xhr.status === 200) callback(xhr.responseText)
        };
        data = data ? JSON.stringify(data) : null;
        xhr.send(data)
    }

    function createSessionCookie(name, value, expirationDays) {
        var d = new Date;
        d.setTime(d.getTime() + expirationDays * 24 * 60 * 60 * 1e3);
        var expires = "expires=" + d.toUTCString();
        document.cookie = name + "=" + value + ";" + expires + ";path=/"
    }

    function getSessionCookie() {
        var name = Environment.sessionCookieName + "=";
        var decodedCookie = decodeURIComponent(document.cookie);
        var ca = decodedCookie.split(";");
        var i = 0;
        var c = null;
        for (i = 0; i < ca.length; i += 1) {
            c = ca[i];
            while (c.charAt(0) === " ") {
                c = c.substring(1)
            }
            if (c.indexOf(name) === 0) {
                return c.substring(name.length, c.length)
            }
        }
        return ""
    }

    function deleteCookie(name) {
        createSessionCookie(name, "", -1)
    }

    function loadCSS() {
        if (document.getElementById("c2LabelStylesheet")) {
            return
        }
        var link = document.createElement("link");
        link.id = "c2LabelStylesheet";
        link.href = Environment.baseUrl + "c2_ecom_styles.css";
        link.type = "text/css";
        link.rel = "stylesheet";
        link.media = "screen,print";
        document.getElementsByTagName("head")[0].appendChild(link)
    }

    function isProductionMode() {
        return Environment.mode !== "local" && Environment.mode !== "test"
    }
    Number.prototype._formatMoney = function(c, d, t) {
        var n = this;
        var s = n < 0 ? "-" : "";
        var i = String(parseInt(n = Math.abs(Number(n) || 0).toFixed(c)));
        var j = (j = i.length) > 3 ? j % 3 : 0;
        c = isNaN(c = Math.abs(c)) ? 2 : c;
        d = d === undefined ? "." : d;
        t = t === undefined ? "," : t;
        return s + (j ? i.substr(0, j) + t : "") + i.substr(j).replace(/(\d{3})(?=\d)/g, "$1" + t) + (c ? d + Math.abs(n - i).toFixed(c).slice(2) : "")
    };
    return {
        init: function() {
            initialize()
        },
        onMessage: function(event) {
            handleMessage(event)
        },
        startOverlayWizard: function(event) {
            startWizard(event)
        },
        refresh: function(amount) {
        	console.log( amount );
            refreshAmount(amount)
        },
        refreshOptionalData: function(optional) {
            refreshOptionalDataAttributes(optional)
        }
    }
}();
if (window.addEventListener) {
    window.addEventListener("message", C2EcomCheckout.onMessage, false)
} else if (window.attachEvent) {
    window.attachEvent("onmessage", C2EcomCheckout.onMessage, false)
}
document.addEventListener("DOMContentLoaded", function(event) {
    "use strict";
    C2EcomCheckout.init()
});