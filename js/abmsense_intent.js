(function ($) {
  // Constants for timing intervals and storage keys
  const UPDATE_INTERVAL = 10000; // 10 seconds
  const DB_UPDATE_INTERVAL = 30000; // 30 seconds
  const STORAGE_KEY = "abmsense_data";
  const TIME_SPENT_INCREMENT = 10; // 10 seconds
  const COOKIE_NAME = "account_id";
  const COOKIE_EXPIRATION_DAYS = 36500; // Approximately 100 years

  // Function to get the current date in mm-dd-yyyy format
  function getCurrentDate() {
    const date = new Date();
    return [
      String(date.getMonth() + 1).padStart(2, "0"),
      String(date.getDate()).padStart(2, "0"),
      date.getFullYear(),
    ].join("-");
  }

  // Function to retrieve stored data from local storage
  function getStoredData() {
    return JSON.parse(localStorage.getItem(STORAGE_KEY)) || {};
  }

  // Function to save data to local storage
  function saveToLocalStorage(data) {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
  }

  // Function to get the page title
  function getPageTitle() {
    return document.title || "";
  }

  // Initialize data for the current page if not already present
  function initializePageData() {
    const data = getStoredData();
    const pageTitle = getPageTitle();
    const currentDate = getCurrentDate();

    if (!data[pageTitle]) {
      data[pageTitle] = {
        page_title: pageTitle,
        time_spent: 0,
        page_view: 0,
        last_update: currentDate,
      };
    }

    if (data[pageTitle].last_update !== currentDate) {
      data[pageTitle].last_update = currentDate;
    }

    data[pageTitle].page_view += 1;
    saveToLocalStorage(data);
  }

  // Function to update the time spent on the current page
  function updateLocalStorage() {
    const data = getStoredData();
    const pageTitle = getPageTitle();

    if (data[pageTitle]) {
      data[pageTitle].time_spent += TIME_SPENT_INCREMENT;
      data[pageTitle].last_update = getCurrentDate();
    }

    saveToLocalStorage(data);
  }

  // Function to send data to the server
  function sendDataToServer() {
    const data = getStoredData();
    const hits = Object.values(data);
    const accountID = getCookie(COOKIE_NAME);

    if (!hits.length) return;

    if (!accountID) {
      console.warn("No account_id found. Retrying...");
      setTimeout(sendDataToServer, 5000); // retry after 5 seconds
      return;
    }

    $.ajax({
      url: abmsense_ajax.ajax_url,
      type: "POST",
      data: {
        action: "abmsense_temp_save",
        security: abmsense_ajax.security,
        hits: JSON.stringify(hits),
        account_id: accountID,
      },
      success(response) {
        if (!response.success) {
          console.error("Server returned an error: ", response.data);
        }
      },
      error(xhr, status, error) {
        console.error(
          "AJAX request failed. Status: ",
          status,
          ", Error: ",
          error
        );
        console.log("XHR response text: ", xhr.responseText);
      },
    });
  }

  // Function to set a cookie to any browser
  function setCookie(name, value, days) {
    const date = new Date();
    date.setTime(date.getTime() + days * 24 * 60 * 60 * 1000);
    document.cookie = `${name}=${value};expires=${date.toUTCString()};path=/`;
  }

  // Function to get a cookie from any browser
  function getCookie(name) {
    const nameEQ = `${name}=`;
    return (
      document.cookie
        .split(";")
        .map((c) => c.trim())
        .find((c) => c.startsWith(nameEQ))
        ?.substring(nameEQ.length) || null
    );
  }

  // Function to initialize the account ID
  function initializeAccountID() {
    let accountID = getCookie(COOKIE_NAME);
    if (!accountID) {
      accountID = `abm_${Math.random().toString(36).substring(2, 15)}`;
      setCookie(COOKIE_NAME, accountID, COOKIE_EXPIRATION_DAYS);
    }
  }

  // Function to initialize the script
  function init() {
    initializeAccountID();
    initializePageData();

    setInterval(updateLocalStorage, UPDATE_INTERVAL);
    setTimeout(sendDataToServer, UPDATE_INTERVAL); // First update after 10 seconds
    setInterval(sendDataToServer, DB_UPDATE_INTERVAL);

    window.addEventListener("beforeunload", () => {
      updateLocalStorage();
      sendDataToServer();
    });
  }

  $(document).ready(init);
})(jQuery);
