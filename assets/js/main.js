/*=============== SHOW MENU ===============*/
const navMenu = document.getElementById('nav-menu'),
      navToggle = document.getElementById('nav-toggle'),
      navClose = document.getElementById('nav-close')

/*===== MENU SHOW =====*/
/* Validate if constant exists */
if(navToggle){
    navToggle.addEventListener('click', () =>{
        navMenu.classList.add('show-menu')
    })
}

/*===== MENU HIDDEN =====*/
/* Validate if constant exists */
if(navClose){
    navClose.addEventListener('click', () =>{
        navMenu.classList.remove('show-menu')
    })
}

/*=============== REMOVE MENU MOBILE ===============*/
const navLink = document.querySelectorAll('.nav__link')

function linkAction(){
    const navMenu = document.getElementById('nav-menu')
    // When we click on each nav__link, we remove the show-menu class
    navMenu.classList.remove('show-menu')
}
navLink.forEach(n => n.addEventListener('click', linkAction))

/*=============== SMOOTH SCROLLING NAVIGATION WITH HISTORY ===============*/
// Map route paths to section IDs
const routeToSectionMap = {
    '/home': 'home',
    '/': 'home',
    '/index': 'home',
    '/about': 'about',
    '/approach': 'approach',
    '/mission': 'mission',
    '/articles': 'articles',
    '/train': 'train',
    '/privacy': 'privacy',
    '/contact': 'contact'
}

// Function to scroll to section smoothly
function scrollToSection(sectionId, updateHistory = true) {
    const section = document.getElementById(sectionId)
    if (!section) return false
    
    const headerHeight = 56 // Header height in pixels
    const sectionTop = section.offsetTop - headerHeight
    
    window.scrollTo({
        top: sectionTop,
        behavior: 'smooth'
    })
    
    if (updateHistory) {
        // Update URL without triggering navigation
        const path = Object.keys(routeToSectionMap).find(
            key => routeToSectionMap[key] === sectionId
        ) || `#${sectionId}`
        
        if (path.startsWith('#')) {
            window.history.pushState({ section: sectionId }, '', path)
        } else {
            window.history.pushState({ section: sectionId }, '', path)
        }
    }
    
    return true
}

// Handle navigation link clicks
document.querySelectorAll('a[href^="/"], a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function(e) {
        const href = this.getAttribute('href')
        
        // Skip external links
        if (href.startsWith('http') || href.startsWith('mailto:') || href.startsWith('tel:')) {
            return
        }
        
        e.preventDefault()
        
        let sectionId = null
        
        // Handle path-based navigation (/about, /mission, etc.)
        if (href.startsWith('/')) {
            sectionId = routeToSectionMap[href]
        }
        // Handle hash-based navigation (#about)
        else if (href.startsWith('#')) {
            sectionId = href.substring(1)
        }
        
        if (sectionId && scrollToSection(sectionId, true)) {
            // Close mobile menu if open
            const navMenu = document.getElementById('nav-menu')
            if (navMenu) {
                navMenu.classList.remove('show-menu')
            }
        }
    })
})

// Handle browser back/forward buttons
window.addEventListener('popstate', function(e) {
    if (e.state && e.state.section) {
        scrollToSection(e.state.section, false)
    } else {
        // Handle URL-based navigation on page load/back
        const path = window.location.pathname
        const hash = window.location.hash.substring(1)
        
        let sectionId = null
        
        if (hash) {
            sectionId = hash
        } else if (routeToSectionMap[path]) {
            sectionId = routeToSectionMap[path]
        }
        
        if (sectionId) {
            scrollToSection(sectionId, false)
        }
    }
})

// Handle initial page load with hash or path
document.addEventListener('DOMContentLoaded', function() {
    const path = window.location.pathname
    const hash = window.location.hash.substring(1)
    
    let sectionId = null
    
    if (hash) {
        sectionId = hash
    } else if (routeToSectionMap[path]) {
        sectionId = routeToSectionMap[path]
    }
    
    if (sectionId) {
        // Small delay to ensure page is rendered
        setTimeout(() => {
            scrollToSection(sectionId, false)
        }, 100)
    }
})

/*=============== CHANGE BACKGROUND HEADER ===============*/
function scrollHeader(){
    const header = document.getElementById('header')
    // When the scroll is greater than 80 viewport height, add the scroll-header class to the header tag
    if(this.scrollY >= 80) header.classList.add('scroll-header'); else header.classList.remove('scroll-header')
}
window.addEventListener('scroll', scrollHeader)

/*=============== QUESTIONS ACCORDION ===============*/
const accordionItems = document.querySelectorAll('.questions__item')

accordionItems.forEach((item) =>{
    const accordionHeader = item.querySelector('.questions__header')

    accordionHeader.addEventListener('click', () =>{
        const openItem = document.querySelector('.accordion-open')

        toggleItem(item)

        if(openItem && openItem!== item){
            toggleItem(openItem)
        }
    })
})

const toggleItem = (item) =>{
    const accordionContent = item.querySelector('.questions__content')

    if(item.classList.contains('accordion-open')){
        accordionContent.removeAttribute('style')
        item.classList.remove('accordion-open')
    }else{
        accordionContent.style.height = accordionContent.scrollHeight + 'px'
        item.classList.add('accordion-open')
    }

}

/*=============== SCROLL SECTIONS ACTIVE LINK ===============*/
const sections = document.querySelectorAll('section[id]')

function scrollActive(){
    const scrollY = window.pageYOffset

    sections.forEach(current =>{
        const sectionHeight = current.offsetHeight,
              sectionTop = current.offsetTop - 58,
              sectionId = current.getAttribute('id')

        if(scrollY > sectionTop && scrollY <= sectionTop + sectionHeight){
            document.querySelector('.nav__menu a[href*=' + sectionId + ']').classList.add('active-link')
        }else{
            document.querySelector('.nav__menu a[href*=' + sectionId + ']').classList.remove('active-link')
        }
    })
}
window.addEventListener('scroll', scrollActive)

/*=============== SHOW SCROLL UP ===============*/ 
function scrollUp(){
    const scrollUp = document.getElementById('scroll-up');
    // When the scroll is higher than 400 viewport height, add the show-scroll class to the a tag with the scroll-top class
    if(this.scrollY >= 400) scrollUp.classList.add('show-scroll'); else scrollUp.classList.remove('show-scroll')
}
window.addEventListener('scroll', scrollUp)

/*=============== DARK LIGHT THEME ===============*/ 
const themeButton = document.getElementById('theme-button')
const darkTheme = 'dark-theme'
const iconTheme = 'ri-sun-line'

// Previously selected topic (if user selected)
const selectedTheme = localStorage.getItem('selected-theme')
const selectedIcon = localStorage.getItem('selected-icon')

// We obtain the current theme that the interface has by validating the dark-theme class
const getCurrentTheme = () => document.body.classList.contains(darkTheme) ? 'dark' : 'light'
const getCurrentIcon = () => themeButton.classList.contains(iconTheme) ? 'ri-moon-line' : 'ri-sun-line'

// We validate if the user previously chose a topic
if (selectedTheme) {
  // If the validation is fulfilled, we ask what the issue was to know if we activated or deactivated the dark
  document.body.classList[selectedTheme === 'dark' ? 'add' : 'remove'](darkTheme)
  themeButton.classList[selectedIcon === 'ri-moon-line' ? 'add' : 'remove'](iconTheme)
}

// Activate / deactivate the theme manually with the button
themeButton.addEventListener('click', () => {
    // Add or remove the dark / icon theme
    document.body.classList.toggle(darkTheme)
    themeButton.classList.toggle(iconTheme)
    // We save the theme and the current icon that the user chose
    localStorage.setItem('selected-theme', getCurrentTheme())
    localStorage.setItem('selected-icon', getCurrentIcon())
})

/*=============== SCROLL REVEAL ANIMATION ===============*/
const sr = ScrollReveal({
    origin: 'top',
    distance: '60px',
    duration: 2500,
    delay: 400,
    // reset: true
})

sr.reveal(`.home__data`)
sr.reveal(`.home__img`, {delay: 500})
sr.reveal(`.home__social`, {delay: 600})
sr.reveal(`.about__img, .contact__box`,{origin: 'left'})
sr.reveal(`.about__data, .contact__form`,{origin: 'right'})
sr.reveal(`.steps__card, .product__card, .questions__group, .footer`,{interval: 100})