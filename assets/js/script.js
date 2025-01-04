document.addEventListener("DOMContentLoaded", () => {
  const wrapper = document.getElementById("partner-wrapper");

  // Ensure the wrapper exists and has scrollable content
  if (wrapper && wrapper.scrollWidth > 0) {
    const content = wrapper.innerHTML;
    const repeatCount = Math.ceil(window.innerWidth / wrapper.scrollWidth) * 10; // Dynamically adjust repetitions
    wrapper.innerHTML = content.repeat(repeatCount);

    const contentWidth = wrapper.scrollWidth; // Access scrollWidth after updating content
    const animationDuration = contentWidth / 100; // Adjust divisor for desired speed

    // Use a CSS variable for animation duration
    wrapper.style.setProperty("--animation-duration", `${animationDuration}s`);
    wrapper.classList.add("scroll-animation"); // Apply animation via CSS class
  } else {
    console.warn("Partner wrapper is missing or has no content. Animation not applied.");
  }
});
