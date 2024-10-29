document.addEventListener("DOMContentLoaded", function () {
  const consentPopupHtml = `
        <div id="abmsense-consent-popup" style="position: fixed; bottom: 0; left: 0; width: 100%; background-color: rgba(0, 0, 0, 0.5); z-index: 9999; display: flex; justify-content: center; align-items: center;">
            <div style="background-color: white; padding: 20px; border-radius: 5px; text-align: center;">
                <p>This website uses cookies to track visitor information.</p>
                <p>By continuing to use this site, you acknowledge the use of cookies.</p>
                <button id="abmsense-consent-agree">Yes, allow cookies</button>
            </div>
        </div>
    `;

  const consentGiven = sessionStorage.getItem("abmsense_consent") === "true";

  if (!consentGiven) {
    document.body.insertAdjacentHTML("beforeend", consentPopupHtml);

    const consentPopup = document.getElementById("abmsense-consent-popup");
    const consentButton = document.getElementById("abmsense-consent-agree");

    consentButton.addEventListener("click", () => {
      consentPopup.style.display = "none";
      sessionStorage.setItem("abmsense_consent", "true");

      // Optionally, notify the server about the consent
      fetch("/wp-admin/admin-ajax.php?action=abmsense_set_consent", {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({ consent: true }),
      });
    });
  }
});
