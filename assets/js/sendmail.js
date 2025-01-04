function sendMail(event) {
    event.preventDefault();

    // Collect form data
    const emailField = document.getElementById("email");
    const subjectField = document.getElementById("subject");
    const messageField = document.getElementById("message");

    const email = emailField.value.trim();
    const subject = subjectField.value.trim();
    const message = messageField.value.trim();

    // Validate form fields
    if (!email || !subject || !message) {
        alert("Please fill in all required fields.");
        return;
    }

    // Validate email address format
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
        alert("Invalid email address.");
        emailField.focus();
        return;
    }

    const params = { email, subject, message };

    // Disable submit button to prevent duplicate submissions
    const submitButton = event.target;
    submitButton.disabled = true;

    emailjs
        .send("service_vgahbke", "template_bayaxj4", params)
        .then(() => {
            alert("Email sent successfully!");
            document.getElementById("contactForm").reset();
        })
        .catch((error) => {
            console.error("Error sending email:", error);
            alert(`An error occurred while sending the email: ${error.text || "Unknown error"}`);
        })
        .finally(() => {
            submitButton.disabled = false; // Re-enable the button
        });
}
