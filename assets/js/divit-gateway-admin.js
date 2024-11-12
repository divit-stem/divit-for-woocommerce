jQuery(function ($) {
  // console.log("admin", wcdivit);

  var installationStatus = wcdivit.installationStatus;

  // web hook test
  function setInstallationStatusText() {
    var text = installationStatus === "yes" ? "ok" : "webhook test not done";
    $("#installationStatusText").text(text);
  }

  function getInstallationStatus() {
    $.ajax({
      method: "POST",
      url: wcdivit.ajaxUrl,
      data: {
        action: "divit_get_installation_status",
      },
      success: (data) => {
        if (data.success) {
          installationStatus = data.data.installationStatus;
          setInstallationStatusText(data.data.installationStatus);
        }
      },
    });
  }

  getInstallationStatus();

  setInterval(() => {
    if (installationStatus !== "yes") {
      getInstallationStatus();
    }
  }, 5 * 1000);
});
