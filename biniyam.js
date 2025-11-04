// Typewriter effect for tagline
document.addEventListener("DOMContentLoaded", function () {
    const tagline = document.querySelector('.tagline');
    if (tagline) {
        const text = tagline.textContent;
        tagline.textContent = '';
        let i = 0;
        function typeWriter() {
            if (i < text.length) {
                tagline.textContent += text.charAt(i);
                i++;
                setTimeout(typeWriter, 50);
            }
        }
        typeWriter();
    }
});

// Animate project cards on scroll
function animateOnScroll() {
    const items = document.querySelectorAll('.project-item');
    const triggerBottom = window.innerHeight * 0.85;
    items.forEach(item => {
        const boxTop = item.getBoundingClientRect().top;
        if (boxTop < triggerBottom) {
            item.style.transform = 'translateY(0)';
            item.style.opacity = '1';
        }
    });
}
window.addEventListener('scroll', animateOnScroll);
window.addEventListener('DOMContentLoaded', () => {
    // Set initial state for animation
    document.querySelectorAll('.project-item').forEach(item => {
        item.style.transform = 'translateY(40px)';
        item.style.opacity = '0';
        item.style.transition = 'transform 0.6s cubic-bezier(.23,1.02,.32,1), opacity 0.6s';
    });
    animateOnScroll();
});

// Social icon hover animation
document.querySelectorAll('.social-icon').forEach(icon => {
    icon.addEventListener('mouseenter', () => {
        icon.style.transform = 'scale(1.2) rotate(-8deg)';
        icon.style.transition = 'transform 0.2s';
    });
    icon.addEventListener('mouseleave', () => {
        icon.style.transform = 'scale(1) rotate(0)';
    });
});

// Optional: Smooth scroll for anchor links (if you add a navbar)
document.querySelectorAll('a[href^="#"]').forEach(link => {
    link.addEventListener('click', function (e) {
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
            e.preventDefault();
            target.scrollIntoView({ behavior: 'smooth' });
        }
    });
});