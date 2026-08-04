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

    // -----------------------------------------------------------------------
    // P3-4: CMS form integration (post-cutover).
    // When served by Instatic, the published page sets window.ETHIOWARE_USE_CMS_FORM = true
    // and the form POSTs to the CMS endpoint instead of EmailJS.
    // Until cutover, the EmailJS path remains the active fallback.
    // -----------------------------------------------------------------------
    if (window.ETHIOWARE_USE_CMS_FORM) {
        const formData = new FormData();
        formData.append("email", email);
        formData.append("subject", subject);
        formData.append("message", message);

        fetch("/_instatic/form/submit", {
            method: "POST",
            body: formData,
        })
            .then((resp) => {
                if (!resp.ok) throw new Error(`Server error: ${resp.status}`);
                return resp.json();
            })
            .then(() => {
                alert("Message sent successfully!");
                document.getElementById("contactForm").reset();
            })
            .catch((error) => {
                console.error("Error sending message:", error);
                alert("An error occurred while sending your message. Please try again.");
            })
            .finally(() => {
                submitButton.disabled = false;
            });
        return;
    }

    // Legacy EmailJS path — active until Instatic cutover (P4-4).
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

