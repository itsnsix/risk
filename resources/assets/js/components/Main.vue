<template>
    <div class="main-outer">
        <div class="main-navbar">
            <span>BATORU</span>

            <!--<div class="help-toggle">?</div>-->
            <div @click="toggleLabels" class="label-toggle">
                <img :class="{active: showLabels}" class="user-label" src="/images/default_avatar.png"/></div>
            <div class="navbar-toggle" @click="toggleEventMenu">
                <div></div>
                <div></div>
                <div></div>
            </div>
        </div>

        <div class="main">
            <div class="map-container">
                <div class="territory-popover" v-if="popover"
                     :style="{'top': popover.y + 'px', 'left': popover.x + 'px'}">
                    <div class="popover-title" :style="{'background-color': popover.territory.occupation.user.color}">
                        <img :src="popover.territory.occupation.user.image ? popover.territory.occupation.user.image : '/images/default_avatar.png'"/>
                        <span>{{popover.territory.occupation.user.name}}</span>
                        <div class="close-popover" @click="closePopover">X</div>
                    </div>
                    <div class="popover-content">
                        <u>Territory {{popover.territory.id}}</u>
                        <div>Captured: <b>{{formatDate(popover.territory.occupation.api_created_at)}}</b></div>

                        <div v-if="popover.territory.occupation.previous_occupation">Previous occupant: <b
                                :style="{color: popover.territory.occupation.previous_occupation.user.color}">
                            {{popover.territory.occupation.previous_occupation.user.name}}</b>
                        </div>
                    </div>
                </div>

                <transition name="loading">
                    <div class="loading" v-if="status">{{status}}</div>
                </transition>

                <canvas @click="onMapClick" id="map" width="5644" height="2177"></canvas>

                <div class="user-labels" v-show="showLabels">
                    <img class="user-label" :src="label.avatar" v-for="label in labels"
                         :id="'territory-' + label.territory.id"
                         :style="{top: label.y + 'px', left: label.x + 'px',
                            height: label.size + 'px', width: label.size + 'px'}">
                </div>
            </div>

            <transition name="slide-menu">
                <div class="sidebar" v-show="showMenu">
                    <div class="sidebar-header">Events</div>

                    <div class="events" v-if="events && events.data && events.data.length > 0">
                        <div class="event" v-for="event in events.data">
                            <div class="event-header">
                                <div class="timestamp">{{event.timestamp}}</div>
                                <div>
                                    <img v-if="event.extra && event.extra.territory_id"
                                         @click="scrollToTerritory(event.extra.territory_id)" src="/images/binoculars.png"/>
                                    <a v-if="event.extra && event.extra.source_url"
                                       :href="event.extra.source_url" target="_blank">
                                        <img src="/images/picture.png"/>
                                    </a>
                                </div>
                            </div>

                            <div class="event-text" v-html="event.text"></div>
                        </div>

                        <button v-if="events.next_page_url" @click="loadMoreEvents" class="btn" :disabled="loadingMoreEvents">
                            {{loadingMoreEvents ? 'Loading...' : 'Load more'}}
                        </button>
                    </div>
                    <div v-else-if="loadingEvents" class="warning-label">Loading events...</div>
                    <div class="warning-label" v-else>Whoops, seems nothing has happened here yet.</div>
                </div>
            </transition>
        </div>
    </div>
</template>

<script>
    import Helpers from '../helpers';

    export default {
	    data() {
	    	return {
			    status: 'Loading map...',
			    territories: {},
                events: {data: []},
                canvas: null,
                context: null,
                labels: [],
			    showMenu: false,
			    popover: null,
                showLabels: true,
                loadingMoreEvents: false,
                loadingEvents: true
	    	}
	    },

	    mounted() {
	    	axios.get('/territories')
                .then(response => {
                    this.territories = response.data;
                    this.setupMap();
                });

            axios.get('/events')
                .then(response => {
                    this.events = response.data;
                    this.loadingEvents = false;
                })
                .catch(response => {
                    this.loadingEvents = false;
                })
        },

	    methods: {
            toggleLabels: function() {
                this.showLabels = !this.showLabels;
            },

	        scrollToTerritory: function(territoryID) {
                let turnOffLabels = !this.showLabels;
                this.showLabels = true; // Needs to be visible in order to scroll to them.
                setTimeout(() => {
                    let el = document.getElementById('territory-' + territoryID);
                    if (el) {
                        el.scrollIntoView({behavior: 'auto', block: 'center', inline: 'center'});

                        this.labels.forEach(l => {
                            if (l.territory.id === territoryID) {
                                this.popover = {
                                    territory: l.territory,
                                    x: l.x - 125,
                                    y: l.y + 15
                                };
                            }
                        });
                    } else {
                        console.log('No label for this territory.')
                    }

                    if (turnOffLabels) this.showLabels = false;
                });
            },

            loadMoreEvents: function() {
                if (!this.loadingMoreEvents) {
                    this.loadingMoreEvents = true;
                    axios.get(this.events.next_page_url)
                        .then(response => {
                            response.data.data = this.events.data.concat(response.data.data);
                            this.events = response.data;
                            this.loadingMoreEvents = false;
                        })
                        .catch(response => {
                            this.loadingMoreEvents = false;
                        });
                }
            },

		    formatDate: function(date) {
		    	return Helpers.formatDate(date);
            },

		    closePopover: function() {
		    	this.popover = null;
            },

		    toggleEventMenu: function() {
		    	this.showMenu = !this.showMenu;
            },

		    onMapClick: function(event) {
                // Show territory occupation if it exists.
			    let t = this.findTerritory({x: event.layerX, y: event.layerY});
			    if (t && t.occupation) {
				    this.popover = {
				    	territory: t,
                        x: event.layerX - 125, // Remove half the width of popover to center it.
                        y: event.layerY + 15
                    };
                } else {
			    	this.popover = null;
                }
            },

            findTerritory: function(location) {
	            let imageData = this.context.getImageData(0, 0, this.canvas.width, this.canvas.height);
	            let color = Helpers.hexToRgb('#18424C'); // Reserved ocean color
	            let coloredPixels = 0;

	            let pixelStack = [[location.x, location.y]];

	            if (!Helpers.validateInitialPixel(imageData, this.canvas.width,
			            pixelStack[0][0], pixelStack[0][1])) {
		            return null;
	            }

	            while (pixelStack.length) {
		            // Where we current are in the image
		            let currentPosition = pixelStack.pop();
		            let x = currentPosition[0];
		            let y = currentPosition[1];
		            let pixelPosition = (y * this.canvas.width + x) * 4;
		            let matchingColor = {
			            r: imageData.data[pixelPosition],
			            g: imageData.data[pixelPosition + 1],
			            b: imageData.data[pixelPosition + 2]
		            };

		            // Walk upwards from position until we hit another color than the one we clicked on
		            while (y-- >= 0 && Helpers.matchPixelColor(imageData, pixelPosition, matchingColor, color)) {
			            pixelPosition -= this.canvas.width * 4;
		            }

		            pixelPosition += this.canvas.width * 4;
		            ++y;
		            let reachLeft = false, reachRight = false;
		            // Walk downwards and look for pixels of the same color to the left and right
		            while (y++ < this.canvas.height - 1 && Helpers.matchPixelColor(imageData, pixelPosition, matchingColor, color)) {
			            Helpers.colorPixel(imageData, pixelPosition, color);
			            coloredPixels++;

			            // Check if these coordinates is a territory
			            if (this.territories.hasOwnProperty(x + '-' + y)) {
			            	return this.territories[x + '-' + y];
                        }

			            // Check left
			            if (x > 0) {
				            if (Helpers.matchPixelColor(imageData, pixelPosition - 4, matchingColor, color)) {
					            if (!reachLeft) {
						            pixelStack.push([x - 1, y]);
						            reachLeft = true;
					            }
				            } else if (reachLeft) {
					            reachLeft = false;
				            }
			            }

			            // Check right
			            if (x < this.canvas.width - 1) {
				            if (Helpers.matchPixelColor(imageData, pixelPosition + 4, matchingColor, color)) {
					            if (!reachRight) {
						            pixelStack.push([x + 1, y]);
						            reachRight = true;
					            }
				            } else if (reachRight) {
					            reachRight = false;
				            }
			            }

			            pixelPosition += this.canvas.width * 4;
		            }

		            if (coloredPixels > 50000) {
			            console.log('Too big area');
			            return null;
		            }
	            }

	            return null;
            },

		    setupMap: function() {
			    this.canvas = document.getElementById('map');
			    this.context = this.canvas.getContext('2d');

			    let image = new Image();
			    image.src = '/images/map.png';
			    image.onload = () => {
				    this.context.drawImage(image, 0, 0);
				    this.context.fill();

				    this.fillOccupations();
				    this.status = null; // Hits when filling all occupations is done.
			    };
			    this.status = 'Drawing map...'; // Hits when map src is set on element.
		    },

		    fillOccupations: function() {
			    let imageData = this.context.getImageData(0, 0, this.canvas.width, this.canvas.height);

                for (let key in this.territories) {
				    if (this.territories.hasOwnProperty(key)) {
				    	let t = this.territories[key];

				    	if (t.occupation) {
						    this.fillTerritory(imageData, {x: t.x, y: t.y}, t.occupation.user.color);
						    this.labelTerritory(t);
					    }
				    }
			    }
		    },

            labelTerritory: function(territory) {
		    	let size = parseInt(territory.size / 200, 10);
		    	if (size > 40) size = 40;
		    	if (size < 10) size = 10;

		    	this.labels.push({
                    territory: territory,
                    x: territory.x - (size / 2),
                    y: territory.y - (size / 2),
                    avatar: territory.occupation.user.image ? territory.occupation.user.image : '/images/default_avatar.png',
                    size: size
                });
            },

		    fillTerritory: function(imageData, location, hexColor) {
			    let color = Helpers.hexToRgb(hexColor);

			    if (!color) {
			    	console.log('Invalid fill color: ' + hexColor);
			    	return false;
                }

                let coloredPixels = 0;
			    let pixelStack = [[location.x, location.y]];

			    if (!Helpers.validateInitialPixel(imageData, this.canvas.width,
                        pixelStack[0][0], pixelStack[0][1])) {
			    	return false;
                }

			    while (pixelStack.length) {
				    // Where we current are in the image
				    let currentPosition = pixelStack.pop();
				    let x = currentPosition[0];
				    let y = currentPosition[1];
				    let pixelPosition = (y * this.canvas.width + x) * 4;
				    let matchingColor = {
					    r: imageData.data[pixelPosition],
					    g: imageData.data[pixelPosition + 1],
					    b: imageData.data[pixelPosition + 2]
				    };

				    // Walk upwards from position until we hit another color than the one we clicked on
				    while (y-- >= 0 && Helpers.matchPixelColor(imageData, pixelPosition, matchingColor, color)) {
					    pixelPosition -= this.canvas.width * 4;
				    }

				    pixelPosition += this.canvas.width * 4;
				    ++y;
				    let reachLeft = false, reachRight = false;
				    // Walk downwards and look for pixels of the same color to the left and right
				    while (y++ < this.canvas.height - 1 && Helpers.matchPixelColor(imageData, pixelPosition, matchingColor, color)) {
					    Helpers.colorPixel(imageData, pixelPosition, color);
                        coloredPixels++;

					    // Check left
					    if (x > 0) {
						    if (Helpers.matchPixelColor(imageData, pixelPosition - 4, matchingColor, color)) {
							    if (!reachLeft) {
								    pixelStack.push([x - 1, y]);
								    reachLeft = true;
							    }
						    } else if (reachLeft) {
							    reachLeft = false;
						    }
					    }

					    // Check right
					    if (x < this.canvas.width - 1) {
						    if (Helpers.matchPixelColor(imageData, pixelPosition + 4, matchingColor, color)) {
							    if (!reachRight) {
								    pixelStack.push([x + 1, y]);
								    reachRight = true;
							    }
						    } else if (reachRight) {
							    reachRight = false;
						    }
					    }

					    pixelPosition += this.canvas.width * 4;
				    }

				    if (coloredPixels > 50000) {
					    console.log('Too big area');
					    return false;
				    }
			    }

			    this.context.putImageData(imageData, 0, 0);
			    return coloredPixels;
		    }
	    }
    }
</script>
