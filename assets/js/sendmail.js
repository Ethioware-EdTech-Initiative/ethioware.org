function sendmessage() {
  const email = document.getElementById("email").value;
  const subject = document.getElementById("subject").value;
  const message = document.getElementById("message").value;
  // Validate form fields
  if (!email || !subject || !message) {
    alert("Please fill in all required fields.");
    return;
  }

  // Validate email address format (optional)
  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  if (!emailRegex.test(email)) {
    alert("Invalid email address.");
    return;
  }

  const parms = {
    email,
    subject,
    message,
  };

  emailjs
    .send("service_hurrcev", "template_bayaxj4", parms)
    .then(() => {
      alert("Email sent successfully!");
    })
    .catch((error) => {
      alert(
        "An error occurred while sending the email. Please try again later"
      );
    });
}
